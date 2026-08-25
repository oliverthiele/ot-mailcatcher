<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\Tests\Unit\Check;

use OliverThiele\OtMailcatcher\Check\RecipientEqualsSenderCheck;
use OliverThiele\OtMailcatcher\Check\Severity;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Sender and recipient being the same address is usually a finisher whose two
 * fields were filled with the same value.
 */
final class RecipientEqualsSenderCheckTest extends UnitTestCase
{
    private RecipientEqualsSenderCheck $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new RecipientEqualsSenderCheck();
    }

    #[Test]
    public function differentAddressesPass(): void
    {
        self::assertSame([], $this->subject->check(CapturedMailFactory::create()));
    }

    #[Test]
    public function theSameAddressOnBothSidesIsReported(): void
    {
        $results = $this->subject->check(CapturedMailFactory::create([
            'from' => 'noreply@example.com',
            'to' => ['noreply@example.com'],
        ]));

        self::assertCount(1, $results);
        self::assertSame('recipientEqualsSender', $results[0]->identifier);
        self::assertSame(Severity::HINT, $results[0]->severity);
    }

    #[Test]
    public function aDisplayNameDoesNotHideTheMatch(): void
    {
        // "WINKEL GmbH <noreply@…>" and "noreply@…" are the same mailbox.
        $results = $this->subject->check(CapturedMailFactory::create([
            'from' => 'WINKEL GmbH <noreply@example.com>',
            'to' => ['noreply@example.com'],
        ]));

        self::assertCount(1, $results);
    }

    #[Test]
    public function aMailWithoutASenderPasses(): void
    {
        // Nothing to compare against; another rule covers the missing sender.
        self::assertSame([], $this->subject->check(CapturedMailFactory::create(['from' => ''])));
    }
}
