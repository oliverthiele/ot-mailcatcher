<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\EventListener;

use OliverThiele\OtMailcatcher\Service\MailcatcherState;
use TYPO3\CMS\Backend\Controller\Event\AfterBackendPageRenderEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use OliverThiele\OtMailcatcher\Service\LabelProvider;

/**
 * Paints a permanent banner into the backend while the catcher is active.
 *
 * The toolbar entry and the reports status are easy to overlook; this one is
 * not. That is the point — no mail leaves the system while this is on.
 */
final class BackendBannerListener
{
    public function __construct(
        private readonly LabelProvider $labelProvider,
    ) {}

    #[AsEventListener('ot-mailcatcher/backend-banner')]
    public function __invoke(AfterBackendPageRenderEvent $event): void
    {
        if (!MailcatcherState::isActive()) {
            return;
        }

        $message = htmlspecialchars($this->labelProvider->get('warning.active.banner'));

        // Only a style attribute, never a <style> element: the backend CSP allows
        // style-src-attr 'unsafe-inline', but an inline <style> block would need a nonce.
        $banner = '<div data-ot-mailcatcher-banner="1"'
            . ' style="position:fixed;left:0;right:0;bottom:0;z-index:9999;padding:.5rem 1rem;'
            . 'background:#c83c3c;color:#fff;font-weight:bold;text-align:center">'
            . $message
            . '</div>';

        $content = $event->getContent();
        $bodyEndPosition = strripos($content, '</body>');

        if ($bodyEndPosition === false) {
            $event->setContent($content . $banner);
            return;
        }

        $event->setContent(
            substr($content, 0, $bodyEndPosition) . $banner . substr($content, $bodyEndPosition)
        );
    }
}
