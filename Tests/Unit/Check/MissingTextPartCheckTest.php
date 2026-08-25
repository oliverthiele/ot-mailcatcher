<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\Tests\Unit\Check;

use OliverThiele\OtMailcatcher\Check\MissingTextPartCheck;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * An HTML-only mail scores worse with spam filters and shows up empty in the
 * clients that display the plain text part.
 */
final class MissingTextPartCheckTest extends UnitTestCase
{
    private MissingTextPartCheck $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new MissingTextPartCheck();
    }

    #[Test]
    public function aMailWithBothPartsPasses(): void
    {
        self::assertSame([], $this->subject->check(CapturedMailFactory::create()));
    }

    #[Test]
    public function htmlWithoutTextIsReported(): void
    {
        $results = $this->subject->check(
            CapturedMailFactory::create(['hasHtmlPart' => true, 'hasTextPart' => false])
        );

        self::assertCount(1, $results);
        self::assertSame('missingTextPart', $results[0]->identifier);
    }

    #[Test]
    public function aMailWithNeitherPartPasses(): void
    {
        // The case that separates this rule from "has no text part": with no HTML
        // either there is no alternative to be missing. Without this the rule
        // could be reduced to !hasTextPart and no test would notice.
        self::assertSame(
            [],
            $this->subject->check(
                CapturedMailFactory::create(['hasHtmlPart' => false, 'hasTextPart' => false])
            )
        );
    }

    #[Test]
    public function aTextOnlyMailPasses(): void
    {
        // The rule is about HTML mails missing their text alternative. A mail
        // that is only text has nothing to be missing — reporting it would make
        // the rule fire on every plain notification the site sends.
        self::assertSame(
            [],
            $this->subject->check(
                CapturedMailFactory::create(['hasHtmlPart' => false, 'hasTextPart' => true])
            )
        );
    }
}
