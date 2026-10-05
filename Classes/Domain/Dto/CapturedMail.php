<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\Domain\Dto;

use OliverThiele\OtMailcatcher\Check\CheckResult;

/**
 * One captured mail. Built from the .eml file on demand — there is no cache and
 * no second copy of the data, the file itself is the single source of truth.
 */
final class CapturedMail
{
    /**
     * @param string[] $to
     * @param string[] $cc
     * @param string[] $bcc
     * @param string[] $replyTo
     * @param CapturedAttachment[] $attachments
     * @param array<int, array{name: string, value: string}> $headers
     * @param CheckResult[] $checkResults
     * @param string $envelopeSender The address the mail is delivered from — the
     *        stored envelope, or for mails captured without one Sender, Return-Path
     *        or From, in the order Symfony uses.
     * @param string[] $envelopeRecipients Every address the mail is delivered to,
     *        Bcc included — the stored envelope, or To, Cc and Bcc for mails
     *        captured without one.
     */
    public function __construct(
        public readonly string $identifier,
        public readonly string $subject,
        public readonly string $from,
        public readonly array $to,
        public readonly array $cc,
        public readonly array $bcc,
        public readonly array $replyTo,
        public readonly ?\DateTimeImmutable $date,
        public readonly int $size,
        public readonly bool $hasHtmlPart,
        public readonly bool $hasTextPart,
        public readonly ?string $context = null,
        public readonly string $textBody = '',
        public readonly string $htmlBody = '',
        public readonly string $rawSource = '',
        public readonly array $attachments = [],
        public readonly array $headers = [],
        public readonly array $checkResults = [],
        public readonly string $envelopeSender = '',
        public readonly array $envelopeRecipients = [],
    ) {
    }

    /**
     * Every address the mail is delivered to: the envelope, or To, Cc and Bcc
     * where no envelope is known.
     *
     * @return string[]
     */
    public function getDeliveryRecipients(): array
    {
        return $this->envelopeRecipients !== [] ? $this->envelopeRecipients : array_merge($this->to, $this->cc, $this->bcc);
    }

    /**
     * The address the mail is delivered from: the envelope sender, or From.
     */
    public function getDeliverySender(): string
    {
        return $this->envelopeSender !== '' ? $this->envelopeSender : $this->from;
    }

    public function getToAsString(): string
    {
        return implode(', ', $this->to);
    }

    /**
     * @param CheckResult[] $checkResults
     */
    public function withCheckResults(array $checkResults): self
    {
        return new self(
            $this->identifier,
            $this->subject,
            $this->from,
            $this->to,
            $this->cc,
            $this->bcc,
            $this->replyTo,
            $this->date,
            $this->size,
            $this->hasHtmlPart,
            $this->hasTextPart,
            $this->context,
            $this->textBody,
            $this->htmlBody,
            $this->rawSource,
            $this->attachments,
            $this->headers,
            $checkResults,
            $this->envelopeSender,
            $this->envelopeRecipients,
        );
    }
}
