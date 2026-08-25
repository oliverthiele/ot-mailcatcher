<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\Tests\Unit\Check;

use OliverThiele\OtMailcatcher\Check\MissingReplyToCheck;
use OliverThiele\OtMailcatcher\Check\Severity;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Without a reply address the answer goes to the site's own noreply mailbox.
 */
final class MissingReplyToCheckTest extends UnitTestCase
{
    private MissingReplyToCheck $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new MissingReplyToCheck();
    }

    #[Test]
    public function aReplyAddressPasses(): void
    {
        self::assertSame([], $this->subject->check(CapturedMailFactory::create()));
    }

    #[Test]
    public function noReplyAddressIsReported(): void
    {
        $results = $this->subject->check(CapturedMailFactory::create(['replyTo' => []]));

        self::assertCount(1, $results);
        self::assertSame('missingReplyTo', $results[0]->identifier);
        self::assertSame(Severity::WARNING, $results[0]->severity);
    }
}
