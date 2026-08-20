<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\EventListener;

use OliverThiele\OtMailcatcher\Service\MailcatcherState;
use TYPO3\CMS\Backend\Backend\Event\SystemInformationToolbarCollectorEvent;
use TYPO3\CMS\Backend\Toolbar\InformationStatus;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use OliverThiele\OtMailcatcher\Service\LabelProvider;

/**
 * Adds a warning to the system information toolbar while the catcher is active.
 */
final class SystemInformationListener
{
    public function __construct(
        private readonly LabelProvider $labelProvider,
    ) {}

    #[AsEventListener('ot-mailcatcher/system-information')]
    public function __invoke(SystemInformationToolbarCollectorEvent $event): void
    {
        if (!MailcatcherState::isActive()) {
            return;
        }

        $event->getToolbarItem()->addSystemMessage(
            $this->labelProvider->get('warning.active.toolbar'),
            InformationStatus::WARNING,
            1,
            'system_mailcatcher'
        );
    }
}
