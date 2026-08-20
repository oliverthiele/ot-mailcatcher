<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\Check;

use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

/**
 * Severity of a check finding, in the wording the module and the test API use.
 */
enum Severity: string
{
    case ERROR = 'error';
    case WARNING = 'warning';
    case HINT = 'hint';

    public function toContextualFeedbackSeverity(): ContextualFeedbackSeverity
    {
        return match ($this) {
            self::ERROR => ContextualFeedbackSeverity::ERROR,
            self::WARNING => ContextualFeedbackSeverity::WARNING,
            self::HINT => ContextualFeedbackSeverity::INFO,
        };
    }

    /**
     * Bootstrap contextual class, for the backend module's badges.
     */
    public function getCssClass(): string
    {
        return match ($this) {
            self::ERROR => 'danger',
            self::WARNING => 'warning',
            self::HINT => 'info',
        };
    }
}
