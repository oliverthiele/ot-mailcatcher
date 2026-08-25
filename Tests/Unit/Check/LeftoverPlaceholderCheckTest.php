<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\Tests\Unit\Check;

use OliverThiele\OtMailcatcher\Check\LeftoverPlaceholderCheck;
use OliverThiele\OtMailcatcher\Check\Severity;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * A placeholder that survived rendering is visible to the recipient.
 *
 * Both patterns are checked in both body parts. The negative case below is what
 * makes that safe: inline CSS does not match the Fluid pattern, so searching the
 * HTML body reports unrendered placeholders without reporting styled mails.
 */
final class LeftoverPlaceholderCheckTest extends UnitTestCase
{
    private LeftoverPlaceholderCheck $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new LeftoverPlaceholderCheck();
    }

    #[Test]
    public function afullyRenderedMailPasses(): void
    {
        self::assertSame([], $this->subject->check(CapturedMailFactory::create()));
    }

    #[Test]
    public function aMarkerInTheHtmlPartIsReported(): void
    {
        $results = $this->subject->check(CapturedMailFactory::create([
            'htmlBody' => '<p>Dear ###FIRST_NAME###,</p>',
        ]));

        self::assertCount(1, $results);
        self::assertSame('leftoverPlaceholder', $results[0]->identifier);
        self::assertSame(Severity::ERROR, $results[0]->severity);
    }

    #[Test]
    public function aMarkerInTheTextPartIsReported(): void
    {
        $results = $this->subject->check(CapturedMailFactory::create([
            'textBody' => 'Dear ###FIRST_NAME###,',
        ]));

        self::assertCount(1, $results);
    }

    #[Test]
    public function anUnresolvedFluidVariableInTheTextPartIsReported(): void
    {
        $results = $this->subject->check(CapturedMailFactory::create([
            'textBody' => 'Dear {user.firstName}, your order is on its way.',
        ]));

        self::assertCount(1, $results);
    }

    #[Test]
    public function inlineCssIsNotMistakenForAPlaceholder(): void
    {
        self::assertSame([], $this->subject->check(CapturedMailFactory::create([
            'htmlBody' => '<style>.button { color: #fff; padding: 4px }</style><p>Hello</p>',
        ])));
    }

    #[Test]
    public function aFluidVariableInTheHtmlPartIsReported(): void
    {
        // The text part is rendered correctly here — only the HTML carries the
        // placeholder, and that is the half most recipients actually see.
        $results = $this->subject->check(CapturedMailFactory::create([
            'htmlBody' => '<p>Dear {user.firstName},</p>',
            'textBody' => 'Dear customer,',
        ]));

        self::assertCount(1, $results);
        self::assertSame('leftoverPlaceholder', $results[0]->identifier);
    }
}
