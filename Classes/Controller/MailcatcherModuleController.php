<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\Controller;

use OliverThiele\OtMailcatcher\Check\CheckRunner;
use OliverThiele\OtMailcatcher\Domain\Repository\CapturedMailRepository;
use OliverThiele\OtMailcatcher\Service\LabelProvider;
use OliverThiele\OtMailcatcher\Service\MailcatcherState;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\AllowedMethodsTrait;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\Response;
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
    ) {}

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

        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->assignMultiple([
            'mails' => $mails,
            'isEnabled' => MailcatcherState::isEnabled(),
            'isAllowed' => MailcatcherState::isAllowed(),
            'isActive' => MailcatcherState::isActive(),
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

        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
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

        return new HtmlResponse($mail->htmlBody);
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

    public function deleteAllAction(): ResponseInterface
    {
        $deleted = $this->capturedMailRepository->deleteAll();

        $this->addFlashMessage(
            sprintf($this->labelProvider->get('flash.deletedAll.message'), $deleted)
        );

        return $this->redirect('index');
    }
}
