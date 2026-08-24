<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\EventListener;

use OliverThiele\OtMailcatcher\Service\ConfigurationValidator;
use OliverThiele\OtMailcatcher\Service\LabelProvider;
use OliverThiele\OtMailcatcher\Service\MailcatcherStatus;
use TYPO3\CMS\Backend\Controller\Event\AfterBackendPageRenderEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;

/**
 * Paints a permanent banner into the backend while the catcher is on.
 *
 * The toolbar entry and the reports status are easy to overlook; this one is
 * not. That is the point — either no mail leaves the system, or the catcher is
 * on but not taking effect and mail leaves the system that nobody expected to.
 */
final class BackendBannerListener
{
    public function __construct(
        private readonly LabelProvider $labelProvider,
        private readonly ConfigurationValidator $configurationValidator,
    ) {}

    #[AsEventListener('ot-mailcatcher/backend-banner')]
    public function __invoke(AfterBackendPageRenderEvent $event): void
    {
        $status = $this->configurationValidator->getStatus();
        if (!$status->needsBanner()) {
            return;
        }

        $message = htmlspecialchars($this->labelProvider->get($status->getBannerLabelKey()));

        // The broken state gets an opaque, darker red and no blur: it must not
        // read as the same routine notice as a working catcher, because it means
        // the opposite — mail is going out.
        $background = $status === MailcatcherStatus::NOT_TAKING_EFFECT
            ? 'rgb(150,20,20)'
            : 'rgba(200,60,60,.88)';
        $blur = $status === MailcatcherStatus::NOT_TAKING_EFFECT
            ? ''
            : 'backdrop-filter:blur(2px);';

        // Only a style attribute, never a <style> element: the backend CSP allows
        // style-src-attr 'unsafe-inline', but an inline <style> block would need a nonce.
        // Anchored right and capped at half the width: a full-width bar covers the
        // last entry of the module menu.
        $banner = '<div data-ot-mailcatcher-banner="' . $status->value . '"'
            . ' style="position:fixed;right:0;bottom:0;z-index:9999;max-width:50%;'
            . 'padding:.4rem 1rem;border-radius:.25rem 0 0 0;'
            . 'background:' . $background . ';color:#fff;font-weight:bold;'
            . 'text-align:right;' . $blur . '">'
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
