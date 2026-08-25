<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\Tests\Unit\Check;

use OliverThiele\OtMailcatcher\Check\SenderIsWebsiteVisitorCheck;
use OliverThiele\OtMailcatcher\Check\Severity;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Sending as the visitor's own address is rejected by SPF and DMARC, so the mail
 * is filed as spam or dropped without a trace.
 */
final class SenderIsWebsiteVisitorCheckTest extends UnitTestCase
{
    private SenderIsWebsiteVisitorCheck $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new SenderIsWebsiteVisitorCheck();
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['defaultMailFromAddress'] = 'noreply@example.com';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']);
        parent::tearDown();
    }

    #[Test]
    public function sendingAsTheSitesOwnDomainPasses(): void
    {
        self::assertSame([], $this->subject->check(
            CapturedMailFactory::create(['from' => 'WINKEL GmbH <noreply@example.com>'])
        ));
    }

    #[Test]
    public function sendingAsAForeignDomainIsReported(): void
    {
        $results = $this->subject->check(
            CapturedMailFactory::create(['from' => 'visitor@gmail.com'])
        );

        self::assertCount(1, $results);
        self::assertSame('senderIsWebsiteVisitor', $results[0]->identifier);
        self::assertSame(Severity::ERROR, $results[0]->severity);
    }

    #[Test]
    public function bothDomainsAreHandedToTheMessage(): void
    {
        // The label reads "sent as %1$s, but this site sends as %2$s" — the order
        // of these two decides whether the sentence makes sense.
        $results = $this->subject->check(
            CapturedMailFactory::create(['from' => 'visitor@gmail.com'])
        );

        self::assertSame(['gmail.com', 'example.com'], $results[0]->arguments);
    }

    #[Test]
    public function withoutAConfiguredSenderDomainTheRuleStaysQuiet(): void
    {
        // Nothing to compare against; reporting every mail would be noise.
        unset($GLOBALS['TYPO3_CONF_VARS']['MAIL']['defaultMailFromAddress']);

        self::assertSame([], $this->subject->check(
            CapturedMailFactory::create(['from' => 'visitor@gmail.com'])
        ));
    }
}
