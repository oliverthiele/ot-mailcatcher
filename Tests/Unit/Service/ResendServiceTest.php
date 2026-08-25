<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\Tests\Unit\Service;

use OliverThiele\OtMailcatcher\Domain\Dto\CapturedMail;
use OliverThiele\OtMailcatcher\Domain\Repository\CapturedMailRepository;
use OliverThiele\OtMailcatcher\Service\ResendService;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Mail\Mailer;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Unit tests for ResendService::describePending().
 *
 * This is the report shown before anything is delivered, and it is the last
 * thing between a staging system cloned from live and a batch of test mail
 * arriving at real customers. It reads the captured mails and the site's own
 * sender domain — no database, no bootstrap — so it can be pinned down here.
 *
 * The counting bug in 0.5.0 is the first case below: reporting distinct external
 * addresses against the total recipient count made fifty mails to one customer
 * read as "50 recipients, 1 of them outside this site".
 */
final class ResendServiceTest extends UnitTestCase
{
    private const OWN_DOMAIN_ADDRESS = 'noreply@example.com';

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['defaultMailFromAddress'] = self::OWN_DOMAIN_ADDRESS;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']);
        parent::tearDown();
    }

    #[Test]
    public function everyDeliveryToOneExternalAddressIsCountedSeparatelyFromTheAddress(): void
    {
        $subject = $this->createSubject([
            $this->createMail('a.eml', ['customer@elsewhere.test']),
            $this->createMail('b.eml', ['customer@elsewhere.test']),
            $this->createMail('c.eml', ['customer@elsewhere.test']),
        ]);

        $result = $subject->describePending();

        self::assertSame(3, $result['mails']);
        self::assertSame(3, $result['recipients']);
        // Three deliveries leave the site …
        self::assertSame(3, $result['external']);
        // … but they all reach the same person.
        self::assertSame(1, $result['externalDistinct']);
        self::assertSame(['customer@elsewhere.test'], $result['externalAddresses']);
    }

    #[Test]
    public function recipientsOnTheSitesOwnDomainDoNotCountAsExternal(): void
    {
        $subject = $this->createSubject([
            $this->createMail('a.eml', ['team@example.com', 'customer@elsewhere.test']),
        ]);

        $result = $subject->describePending();

        self::assertSame(2, $result['recipients']);
        self::assertSame(1, $result['external']);
        self::assertSame(['customer@elsewhere.test'], $result['externalAddresses']);
    }

    #[Test]
    public function copyAndBlindCopyRecipientsAreCountedToo(): void
    {
        $subject = $this->createSubject([
            $this->createMail(
                'a.eml',
                ['to@elsewhere.test'],
                ['cc@elsewhere.test'],
                ['bcc@elsewhere.test']
            ),
        ]);

        $result = $subject->describePending();

        self::assertSame(3, $result['recipients']);
        self::assertSame(3, $result['external']);
        self::assertSame(3, $result['externalDistinct']);
    }

    #[Test]
    public function addressesWithADisplayNameAreNormalisedBeforeDeduplication(): void
    {
        $subject = $this->createSubject([
            $this->createMail('a.eml', ['Customer <customer@elsewhere.test>']),
            $this->createMail('b.eml', ['customer@elsewhere.test']),
            $this->createMail('c.eml', ['CUSTOMER@ELSEWHERE.TEST']),
        ]);

        $result = $subject->describePending();

        self::assertSame(3, $result['external']);
        self::assertSame(1, $result['externalDistinct']);
        self::assertSame(['customer@elsewhere.test'], $result['externalAddresses']);
    }

    #[Test]
    public function withoutAConfiguredSenderDomainEveryRecipientCountsAsExternal(): void
    {
        // Nothing to compare against, so the safe reading is that everything
        // leaves the site rather than that nothing does.
        unset($GLOBALS['TYPO3_CONF_VARS']['MAIL']['defaultMailFromAddress']);

        $subject = $this->createSubject([
            $this->createMail('a.eml', ['team@example.com']),
        ]);

        $result = $subject->describePending();

        self::assertSame(1, $result['external']);
    }

    #[Test]
    public function theLimitStopsCountingAfterThatManyMails(): void
    {
        $subject = $this->createSubject([
            $this->createMail('a.eml', ['one@elsewhere.test']),
            $this->createMail('b.eml', ['two@elsewhere.test']),
            $this->createMail('c.eml', ['three@elsewhere.test']),
        ]);

        $result = $subject->describePending(2);

        self::assertSame(2, $result['mails']);
        self::assertSame(2, $result['recipients']);
        self::assertSame(['one@elsewhere.test', 'two@elsewhere.test'], $result['externalAddresses']);
    }

    #[Test]
    public function nothingCapturedReportsZeros(): void
    {
        $result = $this->createSubject([])->describePending();

        self::assertSame(0, $result['mails']);
        self::assertSame(0, $result['recipients']);
        self::assertSame(0, $result['external']);
        self::assertSame(0, $result['externalDistinct']);
        self::assertSame([], $result['externalAddresses']);
    }

    /**
     * @param CapturedMail[] $mails
     */
    private function createSubject(array $mails): ResendService
    {
        // Stubs, not mocks: these only supply data, no call is asserted on.
        $repository = self::createStub(CapturedMailRepository::class);
        $repository->method('findAll')->willReturn($mails);
        $repository->method('findByIdentifier')->willReturnCallback(
            static function (string $identifier) use ($mails): ?CapturedMail {
                foreach ($mails as $mail) {
                    if ($mail->identifier === $identifier) {
                        return $mail;
                    }
                }

                return null;
            }
        );

        return new ResendService($repository, self::createStub(Mailer::class));
    }

    /**
     * @param string[] $to
     * @param string[] $cc
     * @param string[] $bcc
     */
    private function createMail(string $identifier, array $to, array $cc = [], array $bcc = []): CapturedMail
    {
        return new CapturedMail(
            identifier: $identifier,
            subject: 'Subject',
            from: self::OWN_DOMAIN_ADDRESS,
            to: $to,
            cc: $cc,
            bcc: $bcc,
            replyTo: [],
            date: null,
            size: 100,
            hasHtmlPart: false,
            hasTextPart: true,
            rawSource: 'raw',
        );
    }
}
