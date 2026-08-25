<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\Tests\Unit\Check;

use OliverThiele\OtMailcatcher\Check\BrokenEncodingCheck;
use OliverThiele\OtMailcatcher\Check\Severity;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Replacement characters mean the mail was assembled with the wrong charset —
 * umlauts and special characters are already broken by the time it is captured.
 */
final class BrokenEncodingCheckTest extends UnitTestCase
{
    private BrokenEncodingCheck $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new BrokenEncodingCheck();
    }

    #[Test]
    public function correctlyEncodedUmlautsPass(): void
    {
        // The important negative case: proper UTF-8 must not trigger the rule,
        // or every German mail would be reported.
        self::assertSame([], $this->subject->check(CapturedMailFactory::create([
            'subject' => 'Grüße aus München',
            'textBody' => 'Änderungen an Ihrem Konto — größer, schöner, weiß.',
        ])));
    }

    #[Test]
    public function aReplacementCharacterInTheSubjectIsReported(): void
    {
        $results = $this->subject->check(
            CapturedMailFactory::create(['subject' => "Gr\u{FFFD}\u{FFFD}e"])
        );

        self::assertCount(1, $results);
        self::assertSame('brokenEncoding', $results[0]->identifier);
        self::assertSame(Severity::WARNING, $results[0]->severity);
    }

    #[Test]
    public function aReplacementCharacterInTheBodyIsReported(): void
    {
        $results = $this->subject->check(
            CapturedMailFactory::create(['textBody' => "Sch\u{FFFD}nen Tag"])
        );

        self::assertCount(1, $results);
    }
}
