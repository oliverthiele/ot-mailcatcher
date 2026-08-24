<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\Report;

use OliverThiele\OtMailcatcher\Service\ConfigurationValidator;
use OliverThiele\OtMailcatcher\Service\LabelProvider;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use TYPO3\CMS\Reports\Status;
use TYPO3\CMS\Reports\StatusProviderInterface;

#[AutoconfigureTag('reports.status')]
final class MailcatcherStatusProvider implements StatusProviderInterface
{
    public function __construct(
        private readonly LabelProvider $labelProvider,
        private readonly ConfigurationValidator $configurationValidator,
    ) {
    }

    /**
     * @return Status[]
     */
    public function getStatus(): array
    {
        $status = $this->configurationValidator->getStatus();

        // The administrator is the audience here, so this is the one place that
        // names files, variables and the missing line verbatim.
        $statuses = [
            new Status(
                $this->labelProvider->get('report.title'),
                $this->labelProvider->get($status->getReportValueLabelKey()),
                $this->labelProvider->get($status->getReportMessageLabelKey()),
                $status->toContextualFeedbackSeverity()
            ),
        ];

        foreach ($this->configurationValidator->getEnvironmentFindings() as $finding) {
            $statuses[] = new Status(
                $this->labelProvider->get('report.title'),
                $this->labelProvider->get($finding->getMessageLabelKey()),
                $this->labelProvider->get($finding->getHintLabelKey()),
                $finding->severity->toContextualFeedbackSeverity()
            );
        }

        return $statuses;
    }

    public function getLabel(): string
    {
        return 'ot_mailcatcher';
    }
}
