<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\Tests\Unit\Domain\Repository;

use OliverThiele\OtMailcatcher\Domain\Repository\CapturedMailRepository;
use OliverThiele\OtMailcatcher\Tests\Unit\AbstractStorageTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Everything else in this extension stands on what this class parses: the module
 * list, the API, all ten rules, and the external-recipient report that decides
 * whether a resend is safe. It is also the only place that reads foreign input —
 * MIME written by whatever produced the mail.
 *
 * The identifier guard is covered first. findByIdentifier() builds a filesystem
 * path from a value that arrives over HTTP, and the pattern is what keeps that
 * from becoming a file read of the caller's choosing.
 */
final class CapturedMailRepositoryTest extends AbstractStorageTestCase
{
    private CapturedMailRepository $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new CapturedMailRepository();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unacceptableIdentifiers(): array
    {
        return [
            'parent directory' => ['../../../etc/passwd'],
            'absolute path' => ['/etc/passwd'],
            'nested path' => ['subdir/2026-08-25_100000-abc.eml'],
            'no timestamp prefix' => ['arbitrary.eml'],
            'another extension' => ['2026-08-25_100000-abc.php'],
            'empty' => [''],
            'null byte' => ["2026-08-25_100000-abc.eml\0.txt"],
        ];
    }

    #[Test]
    #[DataProvider('unacceptableIdentifiers')]
    public function anIdentifierThatIsNotACapturedMailNameIsRefused(string $identifier): void
    {
        self::assertNull($this->subject->findByIdentifier($identifier));
    }

    #[Test]
    public function aWellFormedIdentifierForAMissingFileReturnsNull(): void
    {
        // The counterpart to the cases above: they must fail on the pattern, not
        // merely because nothing happens to be there.
        self::assertNull($this->subject->findByIdentifier('2026-08-25_100000-doesnotexist.eml'));
    }

    #[Test]
    public function aPlainTextMailIsParsed(): void
    {
        $this->placeCapturedMail('2026-08-25_100000-plain.eml', implode("\r\n", [
            'Date: Tue, 25 Aug 2026 10:00:00 +0200',
            'Subject: Order confirmation',
            'From: WINKEL GmbH <noreply@example.com>',
            'To: customer@elsewhere.test',
            'Content-Type: text/plain; charset=utf-8',
            '',
            'Thank you for your order.',
        ]));

        $mail = $this->subject->findByIdentifier('2026-08-25_100000-plain.eml');

        self::assertNotNull($mail);
        self::assertSame('Order confirmation', $mail->subject);
        self::assertSame('WINKEL GmbH <noreply@example.com>', $mail->from);
        self::assertSame(['customer@elsewhere.test'], $mail->to);
        self::assertTrue($mail->hasTextPart);
        self::assertFalse($mail->hasHtmlPart);
        self::assertStringContainsString('Thank you for your order.', $mail->textBody);
        self::assertSame('2026-08-25', $mail->date?->format('Y-m-d'));
    }

    #[Test]
    public function anEncodedSubjectIsDecoded(): void
    {
        // Every German mail arrives like this. Without decoding, the module and
        // the API would show the raw encoded word to an editor.
        $this->placeCapturedMail('2026-08-25_100100-encoded.eml', implode("\r\n", [
            'Subject: =?UTF-8?Q?Ihre_Bestellbest=C3=A4tigung?=',
            'From: noreply@example.com',
            'To: customer@elsewhere.test',
            '',
            'Body',
        ]));

        $mail = $this->subject->findByIdentifier('2026-08-25_100100-encoded.eml');

        self::assertSame('Ihre Bestellbestätigung', $mail?->subject);
    }

    #[Test]
    public function everyRecipientOfEveryKindIsCollected(): void
    {
        // describePending() counts these to decide whether a resend reaches real
        // people, so losing one here understates the risk.
        $this->placeCapturedMail('2026-08-25_100200-recipients.eml', implode("\r\n", [
            'Subject: Many recipients',
            'From: noreply@example.com',
            'To: First <one@elsewhere.test>, two@elsewhere.test',
            'Cc: three@elsewhere.test',
            'Bcc: four@elsewhere.test',
            'Reply-To: reply@example.com',
            '',
            'Body',
        ]));

        $mail = $this->subject->findByIdentifier('2026-08-25_100200-recipients.eml');

        self::assertSame(['First <one@elsewhere.test>', 'two@elsewhere.test'], $mail?->to);
        self::assertSame(['three@elsewhere.test'], $mail?->cc);
        self::assertSame(['four@elsewhere.test'], $mail?->bcc);
        self::assertSame(['reply@example.com'], $mail?->replyTo);
    }

    #[Test]
    public function bothPartsOfAMultipartMailAreFound(): void
    {
        $this->placeCapturedMail('2026-08-25_100300-multipart.eml', implode("\r\n", [
            'Subject: Multipart',
            'From: noreply@example.com',
            'To: customer@elsewhere.test',
            'Content-Type: multipart/alternative; boundary="BOUND"',
            '',
            '--BOUND',
            'Content-Type: text/plain; charset=utf-8',
            '',
            'The plain text version.',
            '--BOUND',
            'Content-Type: text/html; charset=utf-8',
            '',
            '<p>The HTML version.</p>',
            '--BOUND--',
        ]));

        $mail = $this->subject->findByIdentifier('2026-08-25_100300-multipart.eml');

        self::assertTrue($mail?->hasTextPart);
        self::assertTrue($mail?->hasHtmlPart);
        self::assertStringContainsString('plain text version', (string)$mail?->textBody);
        self::assertStringContainsString('HTML version', (string)$mail?->htmlBody);
    }

    #[Test]
    public function theListIsNewestFirst(): void
    {
        // The file name carries a sortable timestamp, so the order comes from the
        // name — which only holds while the name keeps that shape.
        $this->placeCapturedMail('2026-08-25_100000-older.eml', "Subject: Older\r\n\r\nBody");
        $this->placeCapturedMail('2026-08-25_120000-newer.eml', "Subject: Newer\r\n\r\nBody");

        $identifiers = array_map(
            static fn($mail) => $mail->identifier,
            $this->subject->findAll()
        );

        self::assertSame(
            ['2026-08-25_120000-newer.eml', '2026-08-25_100000-older.eml'],
            $identifiers
        );
    }

    #[Test]
    public function countingMatchesTheList(): void
    {
        $this->placeCapturedMail('2026-08-25_100000-one.eml');
        $this->placeCapturedMail('2026-08-25_100100-two.eml');

        self::assertSame(2, $this->subject->countAll());
        self::assertCount(2, $this->subject->findAll());
    }

    #[Test]
    public function anEmptyStoreIsNotAnError(): void
    {
        self::assertSame([], $this->subject->findAll());
        self::assertSame(0, $this->subject->countAll());
    }
}
