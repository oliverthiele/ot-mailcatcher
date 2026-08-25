<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\Tests\Unit\Check;

use OliverThiele\OtMailcatcher\Domain\Dto\CapturedMail;

/**
 * Builds a CapturedMail for rule tests.
 *
 * The DTO takes eighteen constructor arguments, most of which no rule looks at.
 * Spelling them out in every test would bury the one field a case is actually
 * about, so this supplies a mail that passes every rule and lets a test override
 * only what it is testing.
 */
final class CapturedMailFactory
{
    /**
     * @param array<string, mixed> $overrides
     */
    public static function create(array $overrides = []): CapturedMail
    {
        $values = array_merge([
            'identifier' => 'test.eml',
            'subject' => 'A subject',
            'from' => 'noreply@example.com',
            'to' => ['recipient@elsewhere.test'],
            'cc' => [],
            'bcc' => [],
            'replyTo' => ['reply@example.com'],
            'date' => null,
            'size' => 1024,
            'hasHtmlPart' => true,
            'hasTextPart' => true,
            'context' => null,
            'textBody' => 'Plain text without anything to complain about.',
            'htmlBody' => '<p>HTML with a <a href="https://example.com">good link</a>.</p>',
            'rawSource' => 'raw',
            'attachments' => [],
            'headers' => [],
            'checkResults' => [],
        ], $overrides);

        return new CapturedMail(
            identifier: $values['identifier'],
            subject: $values['subject'],
            from: $values['from'],
            to: $values['to'],
            cc: $values['cc'],
            bcc: $values['bcc'],
            replyTo: $values['replyTo'],
            date: $values['date'],
            size: $values['size'],
            hasHtmlPart: $values['hasHtmlPart'],
            hasTextPart: $values['hasTextPart'],
            context: $values['context'],
            textBody: $values['textBody'],
            htmlBody: $values['htmlBody'],
            rawSource: $values['rawSource'],
            attachments: $values['attachments'],
            headers: $values['headers'],
            checkResults: $values['checkResults'],
        );
    }
}
