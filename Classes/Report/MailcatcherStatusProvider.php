<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\Report;

use OliverThiele\OtMailcatcher\Service\MailcatcherState;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use OliverThiele\OtMailcatcher\Service\LabelProvider;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Reports\Status;
use TYPO3\CMS\Reports\StatusProviderInterface;

#[AutoconfigureTag('reports.status')]
final class MailcatcherStatusProvider implements StatusProviderInterface
{
    public function __construct(
        private readonly LabelProvider $labelProvider,
    ) {}

    /**
     * @return Status[]
     */
    public function getStatus(): array
    {
        $title = $this->labelProvider->get('report.title');

        if (!MailcatcherState::isActive()) {
            return [
                new Status(
                    $title,
                    $this->labelProvider->get('report.inactive.value'),
                    $this->labelProvider->get('report.inactive.message'),
                    ContextualFeedbackSeverity::OK
                ),
            ];
        }

        return [
            new Status(
                $title,
                $this->labelProvider->get('report.active.value'),
                $this->labelProvider->get('report.active.message'),
                ContextualFeedbackSeverity::WARNING
            ),
        ];
    }

    public function getLabel(): string
    {
        return 'ot_mailcatcher';
    }
}
