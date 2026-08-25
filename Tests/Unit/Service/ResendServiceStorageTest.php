<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\Tests\Unit\Service;

use OliverThiele\OtMailcatcher\Domain\Repository\CapturedMailRepository;
use OliverThiele\OtMailcatcher\Service\ResendService;
use OliverThiele\OtMailcatcher\Tests\Unit\AbstractStorageTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\RawMessage;
use TYPO3\CMS\Core\Mail\Mailer;

/**
 * Sending is the one operation here that reaches outside the machine, and the
 * one that cannot be taken back. These tests use the real repository against a
 * throwaway storage directory, so the file handling is exercised rather than
 * described: whether a delivered mail actually leaves the store, and whether a
 * failed one actually stays.
 *
 * That second half is the point. A mail that was delivered but not moved would
 * be delivered again on the next run, and a mail dropped after a failed send
 * would be gone for good — it was never delivered to anyone.
 */
final class ResendServiceStorageTest extends AbstractStorageTestCase
{
    private const MAIL = "Subject: Order confirmation\r\n"
        . "From: WINKEL GmbH <noreply@example.com>\r\n"
        . "To: customer@elsewhere.test\r\n"
        . "Cc: office@example.com\r\n"
        . "\r\n"
        . 'Body';

    protected function setUp(): void
    {
        parent::setUp();
        $this->switchCatcher(false);
    }

    /**
     * @param \Throwable|null $failWith Makes every send fail with this exception.
     * @param list<Envelope> $recordedEnvelopes
     */
    private function service(?\Throwable $failWith = null, array &$recordedEnvelopes = []): ResendService
    {
        // A stub, not a mock: no call is asserted on — the envelope is checked
        // through what the callback records.
        $mailer = self::createStub(Mailer::class);
        $mailer->method('send')->willReturnCallback(
            static function (RawMessage $message, ?Envelope $envelope = null) use ($failWith, &$recordedEnvelopes): void {
                if ($failWith !== null) {
                    throw $failWith;
                }
                if ($envelope !== null) {
                    $recordedEnvelopes[] = $envelope;
                }
            }
        );

        return new ResendService(new CapturedMailRepository(), $mailer);
    }

    private function sentDirectory(): string
    {
        return $this->storageDirectory . '/sent';
    }

    #[Test]
    public function sendingIsRefusedWhileTheCatcherIsOn(): void
    {
        // Otherwise the mail would be captured again the moment it is sent.
        $this->switchCatcher(true);
        $this->placeCapturedMail('2026-08-25_100000-a.eml', self::MAIL);

        $this->expectException(\RuntimeException::class);
        $this->service()->resendOne('2026-08-25_100000-a.eml');
    }

    #[Test]
    public function anUnknownIdentifierIsReportedRatherThanThrown(): void
    {
        $error = $this->service()->resendOne('2026-08-25_100000-missing.eml');

        self::assertIsString($error);
        self::assertStringContainsString('could not be read', $error);
    }

    #[Test]
    public function aDeliveredMailLeavesTheStoreAndIsKept(): void
    {
        $this->placeCapturedMail('2026-08-25_100000-a.eml', self::MAIL);

        self::assertNull($this->service()->resendOne('2026-08-25_100000-a.eml'));

        self::assertFileDoesNotExist($this->storageDirectory . '/2026-08-25_100000-a.eml');
        self::assertFileExists($this->sentDirectory() . '/2026-08-25_100000-a.eml');
    }

    #[Test]
    public function theEnvelopeCarriesTheSenderAndEveryRecipient(): void
    {
        // The envelope decides who actually receives it — the headers only
        // decide what they see.
        $this->placeCapturedMail('2026-08-25_100000-a.eml', self::MAIL);
        $envelopes = [];

        $this->service(recordedEnvelopes: $envelopes)->resendOne('2026-08-25_100000-a.eml');

        self::assertCount(1, $envelopes);
        self::assertSame('noreply@example.com', $envelopes[0]->getSender()->getAddress());
        self::assertSame(
            ['customer@elsewhere.test', 'office@example.com'],
            array_map(static fn($address) => $address->getAddress(), $envelopes[0]->getRecipients())
        );
    }

    #[Test]
    public function aFailedSendLeavesTheMailWhereItIs(): void
    {
        // The only copy. Dropping it would destroy a mail nobody ever received.
        $this->placeCapturedMail('2026-08-25_100000-a.eml', self::MAIL);

        $error = $this->service(new TransportException('relay refused'))
            ->resendOne('2026-08-25_100000-a.eml');

        self::assertIsString($error);
        self::assertStringContainsString('relay refused', $error);
        self::assertFileExists($this->storageDirectory . '/2026-08-25_100000-a.eml');
        self::assertDirectoryDoesNotExist($this->sentDirectory());
    }

    #[Test]
    public function aBulkRunStopsAfterThreeFailuresInARow(): void
    {
        // A relay that refuses the fourth message refuses the fortieth, and
        // hammering it is how an account gets throttled.
        foreach (range(1, 6) as $index) {
            $this->placeCapturedMail(sprintf('2026-08-25_10%02d00-mail.eml', $index), self::MAIL);
        }

        $result = $this->service(new TransportException('relay refused'))->resendAll();

        self::assertSame(0, $result['sent']);
        self::assertSame(3, $result['failed']);
        self::assertTrue($result['stoppedEarly']);
        // Everything is still there, including the ones never attempted.
        self::assertCount(6, glob($this->storageDirectory . '/*.eml') ?: []);
    }

    #[Test]
    public function theLimitStopsAfterThatManyMails(): void
    {
        foreach (range(1, 5) as $index) {
            $this->placeCapturedMail(sprintf('2026-08-25_10%02d00-mail.eml', $index), self::MAIL);
        }

        $result = $this->service()->resendAll(2);

        self::assertSame(2, $result['sent']);
        self::assertFalse($result['stoppedEarly']);
        self::assertCount(3, glob($this->storageDirectory . '/*.eml') ?: []);
        self::assertCount(2, glob($this->sentDirectory() . '/*.eml') ?: []);
    }
}
