<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\EventListener;

use OliverThiele\OtMailcatcher\Service\ConfigurationValidator;
use OliverThiele\OtMailcatcher\Service\LabelProvider;
use OliverThiele\OtMailcatcher\Service\MailcatcherStatus;
use TYPO3\CMS\Backend\Backend\Event\SystemInformationToolbarCollectorEvent;
use TYPO3\CMS\Backend\Toolbar\InformationStatus;
use TYPO3\CMS\Core\Attribute\AsEventListener;

/**
 * Adds a warning to the system information toolbar while the catcher is on.
 */
final class SystemInformationListener
{
    public function __construct(
        private readonly LabelProvider $labelProvider,
        private readonly ConfigurationValidator $configurationValidator,
    ) {}

    #[AsEventListener('ot-mailcatcher/system-information')]
    public function __invoke(SystemInformationToolbarCollectorEvent $event): void
    {
        $status = $this->configurationValidator->getStatus();
        if (!$status->needsBanner()) {
            return;
        }

        $event->getToolbarItem()->addSystemMessage(
            $this->labelProvider->get($status->getToolbarLabelKey()),
            $status === MailcatcherStatus::NOT_TAKING_EFFECT
                ? InformationStatus::ERROR
                : InformationStatus::WARNING,
            1,
            'system_mailcatcher'
        );
    }
}
