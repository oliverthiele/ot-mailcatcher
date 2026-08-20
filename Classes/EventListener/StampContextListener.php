<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\EventListener;

use OliverThiele\OtMailcatcher\Domain\Repository\CapturedMailRepository;
use OliverThiele\OtMailcatcher\Service\MailcatcherState;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Mime\Message;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Mail\Event\BeforeMailerSentMessageEvent;

/**
 * Records where a mail came from, as an extra header on the message.
 *
 * The transport sees every mail but not its origin. A dedicated form finisher
 * would know the origin but would only cover forms that opted into it — this
 * event covers everything with a single hook, and it is the API TYPO3 v14 keeps
 * (the former AfterMailerInitializationEvent was removed there).
 *
 * Only stamps while the catcher is active: this header is a debugging aid, it
 * has no business travelling to a real recipient.
 */
final class StampContextListener
{
    #[AsEventListener('ot-mailcatcher/stamp-context')]
    public function __invoke(BeforeMailerSentMessageEvent $event): void
    {
        if (!MailcatcherState::isActive()) {
            return;
        }

        $message = $event->getMessage();
        if (!$message instanceof Message) {
            return;
        }

        $headers = $message->getHeaders();
        if ($headers->has(CapturedMailRepository::CONTEXT_HEADER)) {
            return;
        }

        $headers->addTextHeader(CapturedMailRepository::CONTEXT_HEADER, $this->describeOrigin());
    }

    private function describeOrigin(): string
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if (!$request instanceof ServerRequestInterface) {
            return 'CLI';
        }

        $parts = [(string)$request->getUri()];

        // Null on backend and CLI requests; the frontend request attribute is
        // typed by the core, so no further narrowing is needed here.
        $pageInformation = $request->getAttribute('frontend.page.information');
        if ($pageInformation !== null) {
            $parts[] = 'page=' . $pageInformation->getId();
        }

        $formIdentifier = $this->extractFormIdentifier($request);
        if ($formIdentifier !== null) {
            $parts[] = 'form=' . $formIdentifier;
        }

        return implode(' | ', $parts);
    }

    /**
     * EXT:form posts its state below tx_form_formframework[<identifier>], which
     * is the only place the form identifier appears in the request.
     */
    private function extractFormIdentifier(ServerRequestInterface $request): ?string
    {
        $parsedBody = $request->getParsedBody();
        if (!is_array($parsedBody)) {
            return null;
        }

        $formData = $parsedBody['tx_form_formframework'] ?? null;
        if (!is_array($formData)) {
            return null;
        }

        foreach (array_keys($formData) as $identifier) {
            if (is_string($identifier) && $identifier !== '') {
                return $identifier;
            }
        }

        return null;
    }
}
