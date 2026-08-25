<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\Tests\Unit\Check;

use OliverThiele\OtMailcatcher\Check\Severity;
use OliverThiele\OtMailcatcher\Check\UnresolvedTypo3LinkCheck;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * A t3:// reference that survived rendering reaches the recipient as raw text
 * instead of a working link.
 */
final class UnresolvedTypo3LinkCheckTest extends UnitTestCase
{
    private UnresolvedTypo3LinkCheck $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new UnresolvedTypo3LinkCheck();
    }

    #[Test]
    public function resolvedLinksPass(): void
    {
        self::assertSame([], $this->subject->check(CapturedMailFactory::create()));
    }

    #[Test]
    public function anUnresolvedLinkInHtmlIsReported(): void
    {
        $results = $this->subject->check(CapturedMailFactory::create([
            'htmlBody' => '<a href="t3://page?uid=12">Read more</a>',
        ]));

        self::assertCount(1, $results);
        self::assertSame('unresolvedTypo3Link', $results[0]->identifier);
        self::assertSame(Severity::ERROR, $results[0]->severity);
    }

    #[Test]
    public function anUnresolvedLinkInTheTextPartIsReportedToo(): void
    {
        $results = $this->subject->check(CapturedMailFactory::create([
            'textBody' => 'See t3://page?uid=12 for details',
        ]));

        self::assertCount(1, $results);
    }
}
