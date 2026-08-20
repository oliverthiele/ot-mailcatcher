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
        // Anchored right and capped at half the width: a full-width bar covers the
        // last entry of the module menu. Slightly translucent so what is behind it
        // stays readable.
        $banner = '<div data-ot-mailcatcher-banner="1"'
            . ' style="position:fixed;right:0;bottom:0;z-index:9999;max-width:50%;'
            . 'padding:.4rem 1rem;border-radius:.25rem 0 0 0;'
            . 'background:rgba(200,60,60,.88);color:#fff;font-weight:bold;'
            . 'text-align:right;backdrop-filter:blur(2px)">'
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
