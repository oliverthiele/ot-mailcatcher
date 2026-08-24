<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\Service;

use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

/**
 * The effective state of the catcher, as the backend must report it.
 *
 * Two independent facts span this enum: whether the switch is on and permitted
 * (MailcatcherState::isActive()) and whether the mail transport actually points
 * at the catcher (MailcatcherState::isWired()). Both mismatches matter, and in
 * opposite directions — NOT_TAKING_EFFECT means mails go out while the backend
 * would otherwise claim they do not, STRAY_TRANSPORT means the reverse.
 *
 * The label keys are derived from the case value by convention, so a new case
 * cannot silently render an empty string in one of the four places that report
 * the state.
 */
enum MailcatcherStatus: string
{
    /** Switched on and wired up — mails are captured. */
    case ACTIVE = 'active';

    /** Switched on, but the transport was never wired up — mails are still sent. */
    case NOT_TAKING_EFFECT = 'notTakingEffect';

    /** Switched off, yet the transport points at the catcher — mails are not sent. */
    case STRAY_TRANSPORT = 'strayTransport';

    /** Switched on, but not permitted in this environment — mails are sent. */
    case LOCKED = 'locked';

    /** Switched off and not wired up — normal operation. */
    case INACTIVE = 'inactive';

    /**
     * Whether outgoing mail actually reaches its recipients in this state.
     */
    public function isMailBeingSent(): bool
    {
        return $this === self::NOT_TAKING_EFFECT
            || $this === self::LOCKED
            || $this === self::INACTIVE;
    }

    /**
     * Whether this state warrants the permanent backend banner. Only the two
     * states an editor can act on qualify — a banner that also appears during
     * normal operation is a banner nobody reads.
     */
    public function needsBanner(): bool
    {
        return $this === self::ACTIVE || $this === self::NOT_TAKING_EFFECT;
    }

    public function getStatusLabelKey(): string
    {
        return 'status.' . $this->value;
    }

    /**
     * Extra line of explanation shown under the status in the backend module,
     * for the two states that need one. Null keeps the module quiet in the
     * states that speak for themselves.
     */
    public function getHintLabelKey(): ?string
    {
        return match ($this) {
            self::NOT_TAKING_EFFECT, self::STRAY_TRANSPORT, self::LOCKED => 'status.' . $this->value . '.hint',
            self::ACTIVE, self::INACTIVE => null,
        };
    }

    public function getBannerLabelKey(): string
    {
        return 'warning.' . $this->value . '.banner';
    }

    public function getToolbarLabelKey(): string
    {
        return 'warning.' . $this->value . '.toolbar';
    }

    public function getReportValueLabelKey(): string
    {
        return 'report.' . $this->value . '.value';
    }

    public function getReportMessageLabelKey(): string
    {
        return 'report.' . $this->value . '.message';
    }

    public function toContextualFeedbackSeverity(): ContextualFeedbackSeverity
    {
        return match ($this) {
            self::NOT_TAKING_EFFECT => ContextualFeedbackSeverity::ERROR,
            self::ACTIVE, self::LOCKED => ContextualFeedbackSeverity::WARNING,
            self::STRAY_TRANSPORT => ContextualFeedbackSeverity::INFO,
            self::INACTIVE => ContextualFeedbackSeverity::OK,
        };
    }

    /**
     * Backend callout variant for the module's status box.
     *
     * A callout rather than a Bootstrap background utility: `.callout-*` sets
     * background *and* text colour from theme-aware tokens, while
     * `.bg-*-subtle` only sets the background — which left light text on a light
     * ground in the dark backend theme.
     */
    public function getCssClass(): string
    {
        return match ($this) {
            self::NOT_TAKING_EFFECT => 'danger',
            self::ACTIVE, self::LOCKED => 'warning',
            self::STRAY_TRANSPORT => 'info',
            self::INACTIVE => 'secondary',
        };
    }

}
