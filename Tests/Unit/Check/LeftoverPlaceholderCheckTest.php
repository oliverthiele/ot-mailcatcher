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
 * Note the asymmetry between the two body parts: `###MARKER###` is looked for in
 * both, a Fluid-style `{variable}` only in the plain text part. The tests below
 * pin that as it currently stands — but measured against the pattern, inline CSS
 * (`{ color: #fff }`, `{margin:0}`) does not match it, while `{user.firstName}`
 * in an HTML body does and would be a real finding. The restriction therefore
 * hides something rather than protecting against noise; see the note in the
 * README backlog.
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
    public function aFluidVariableInTheHtmlPartIsCurrentlyNotReported(): void
    {
        // Pins the current behaviour, not a desirable one: this is an unrendered
        // placeholder the recipient would see, and the rule stays quiet because
        // it only searches the text part. Changing it is a product decision.
        self::assertSame([], $this->subject->check(CapturedMailFactory::create([
            'htmlBody' => '<p>Dear {user.firstName},</p>',
            'textBody' => 'Dear customer,',
        ])));
    }
}
