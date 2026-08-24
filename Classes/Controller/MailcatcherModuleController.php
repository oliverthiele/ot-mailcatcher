<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\Controller;

use OliverThiele\OtMailcatcher\Check\CheckRunner;
use OliverThiele\OtMailcatcher\Domain\Repository\CapturedMailRepository;
use OliverThiele\OtMailcatcher\Service\ConfigurationValidator;
use OliverThiele\OtMailcatcher\Service\LabelProvider;
use OliverThiele\OtMailcatcher\Service\MailcatcherState;
use OliverThiele\OtMailcatcher\Service\ResendService;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\AllowedMethodsTrait;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * Reviews the mails captured by FileTransport.
 *
 * The captured files carry no page context, so this is a "system" module rather
 * than a page-tree-bound "web" module.
 */
class MailcatcherModuleController extends ActionController
{
    use AllowedMethodsTrait;

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly CapturedMailRepository $capturedMailRepository,
        private readonly CheckRunner $checkRunner,
        private readonly LabelProvider $labelProvider,
        private readonly PageRenderer $pageRenderer,
        private readonly ConfigurationValidator $configurationValidator,
        private readonly ResendService $resendService,
    ) {
    }

    /**
     * Builds the module template with this controller's flash message queue.
     *
     * Without the queue, nothing this module reports ever reaches the screen.
     * The core layout renders one specific queue —
     * <f:flashMessages queueIdentifier="{flashMessageQueueIdentifier}" /> — and
     * that identifier comes from ModuleTemplate, while addFlashMessage() writes
     * into Extbase's own plugin-namespaced queue. The two only meet if they are
     * connected here.
     */
    private function createModuleTemplate(): ModuleTemplate
    {
        return $this->moduleTemplateFactory->create($this->request)
            ->setFlashMessageQueue($this->getFlashMessageQueue());
    }

    public function indexAction(): ResponseInterface
    {
        $mails = [];
        foreach ($this->capturedMailRepository->findAll() as $mail) {
            $results = $this->checkRunner->run($mail);
            $mails[] = [
                'mail' => $mail->withCheckResults($results),
                'highestSeverity' => $this->checkRunner->getHighestSeverity($results),
            ];
        }

        $moduleTemplate = $this->createModuleTemplate();
        $moduleTemplate->assignMultiple([
            'mails' => $mails,
            'isEnabled' => MailcatcherState::isEnabled(),
            'isAllowed' => MailcatcherState::isAllowed(),
            'status' => $this->configurationValidator->getStatus(),
            'enabledSince' => MailcatcherState::getEnabledSince()?->format('d.m.Y H:i'),
            'environmentFindings' => $this->configurationValidator->getEnvironmentFindings(),
            'storageDirectory' => MailcatcherState::getStorageDirectory(),
        ]);

        return $moduleTemplate->renderResponse('MailcatcherModule/Index');
    }

    public function showAction(string $identifier): ResponseInterface
    {
        $mail = $this->capturedMailRepository->findByIdentifier($identifier);
        if ($mail === null) {
            $this->addFlashMessage(
                $this->labelProvider->get('flash.notFound.message'),
                '',
                ContextualFeedbackSeverity::ERROR
            );
            return $this->redirect('index');
        }

        $mail = $mail->withCheckResults($this->checkRunner->run($mail));

        // The backend does not wire data-bs-toggle="tab" on its own — Bootstrap's
        // JavaScript is in the importmap but never loaded, so without this the
        // tab buttons change state while their panes stay hidden.
        //
        // v14 renamed the module: "tabs.js" is only a deprecation shim there and
        // logs a warning, while v13.4 has no "tab.js" at all.
        $this->pageRenderer->loadJavaScriptModule(
            (new Typo3Version())->getMajorVersion() >= 14
                ? '@typo3/backend/tab.js'
                : '@typo3/backend/tabs.js'
        );

        $moduleTemplate = $this->createModuleTemplate();
        $moduleTemplate->assignMultiple([
            'mail' => $mail,
            'bodyUri' => $this->uriBuilder->reset()->uriFor('body', ['identifier' => $identifier]),
        ]);

        return $moduleTemplate->renderResponse('MailcatcherModule/Show');
    }

    /**
     * Delivers the mail's HTML part on its own, to be shown inside a sandboxed
     * iframe. Never rendered into the module template: the content is foreign
     * and must not share the backend document.
     */
    public function bodyAction(string $identifier): ResponseInterface
    {
        $mail = $this->capturedMailRepository->findByIdentifier($identifier);
        if ($mail === null) {
            return new HtmlResponse('', 404);
        }

        // Own CSP for this one response. The backend policy allows img-src 'self'
        // only, which blocks every logo and every remote image a real mail uses —
        // exactly what an editor opens the preview to look at. TYPO3's CSP
        // middleware leaves a response alone once it carries its own header
        // (ContentSecurityPolicyHeaders::process()), so this stays scoped to the
        // preview and does not relax the backend. Scripts and frames stay denied
        // through `default-src 'none'` on top of the iframe's sandbox attribute.
        return (new HtmlResponse($mail->htmlBody))->withHeader(
            'Content-Security-Policy',
            "default-src 'none'; img-src * data:; style-src * 'unsafe-inline'; font-src * data:"
        );
    }

    public function attachmentAction(string $identifier, int $part): ResponseInterface
    {
        $attachment = $this->capturedMailRepository->getAttachment($identifier, $part);
        if ($attachment === null) {
            return new HtmlResponse('', 404);
        }

        $response = new Response();
        $response->getBody()->write($attachment['content']);

        return $response
            ->withHeader('Content-Type', $attachment['mimeType'])
            ->withHeader(
                'Content-Disposition',
                'attachment; filename="' . str_replace('"', '', $attachment['fileName']) . '"'
            );
    }

    public function initializeToggleAction(): void
    {
        $this->assertAllowedHttpMethod($this->request, 'POST');
    }

    public function toggleAction(): ResponseInterface
    {
        if (!MailcatcherState::isAllowed()) {
            $this->addFlashMessage(
                $this->labelProvider->get('flash.notAllowed.message'),
                '',
                ContextualFeedbackSeverity::ERROR
            );
            return $this->redirect('index');
        }

        $enabled = !MailcatcherState::isEnabled();
        MailcatcherState::setEnabled($enabled);

        $this->addFlashMessage(
            $this->labelProvider->get($enabled ? 'flash.enabled.message' : 'flash.disabled.message'),
            '',
            $enabled ? ContextualFeedbackSeverity::WARNING : ContextualFeedbackSeverity::OK
        );

        return $this->redirect('index');
    }

    public function initializeDeleteAction(): void
    {
        $this->assertAllowedHttpMethod($this->request, 'POST');
    }

    public function deleteAction(string $identifier): ResponseInterface
    {
        $this->capturedMailRepository->delete($identifier);

        return $this->redirect('index');
    }

    public function initializeDeleteAllAction(): void
    {
        $this->assertAllowedHttpMethod($this->request, 'POST');
    }

    /**
     * Asks before anything irreversible happens.
     *
     * A rendered step rather than a JavaScript dialog: it works regardless of
     * what the backend loads, it survives a reload, and the friction is the
     * point — on a live system these two actions decide whether real customer
     * mail is destroyed or delivered.
     */
    public function confirmAction(string $operation): ResponseInterface
    {
        if (!in_array($operation, ['deleteAll', 'resendAll'], true)) {
            return $this->redirect('index');
        }

        $moduleTemplate = $this->createModuleTemplate();
        $moduleTemplate->assignMultiple([
            'operation' => $operation,
            'count' => $this->capturedMailRepository->countAll(),
            'isProduction' => Environment::getContext()->isProduction(),
            // Assembled here rather than inline in Fluid: an inline f:if needs
            // quotes nested inside quotes, which is exactly how the status box
            // broke once already.
            'variant' => $operation === 'deleteAll' ? 'danger' : 'warning',
        ]);

        return $moduleTemplate->renderResponse('MailcatcherModule/Confirm');
    }

    public function deleteAllAction(): ResponseInterface
    {
        $deleted = $this->capturedMailRepository->deleteAll();

        $this->addFlashMessage(
            sprintf($this->labelProvider->get('flash.deletedAll.message'), $deleted)
        );

        return $this->redirect('index');
    }

    public function initializeResendAllAction(): void
    {
        $this->assertAllowedHttpMethod($this->request, 'POST');
    }

    public function resendAllAction(): ResponseInterface
    {
        try {
            $result = $this->resendService->resendAll();
        } catch (\RuntimeException $exception) {
            $this->addFlashMessage(
                $exception->getMessage(),
                '',
                ContextualFeedbackSeverity::ERROR
            );
            return $this->redirect('index');
        }

        if ($result['sent'] > 0) {
            $this->addFlashMessage(
                sprintf($this->labelProvider->get('flash.resentAll.message'), $result['sent']),
                '',
                ContextualFeedbackSeverity::OK
            );
        }

        if ($result['failed'] > 0) {
            $this->addFlashMessage(
                sprintf($this->labelProvider->get('flash.resendFailed.message'), $result['failed'])
                    . ' ' . implode(' | ', array_slice($result['errors'], 0, 5)),
                '',
                ContextualFeedbackSeverity::ERROR
            );
        }

        return $this->redirect('index');
    }
}
