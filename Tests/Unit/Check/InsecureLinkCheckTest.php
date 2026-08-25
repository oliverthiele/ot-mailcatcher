<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\Tests\Unit\Check;

use OliverThiele\OtMailcatcher\Check\InsecureLinkCheck;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * The only rule that reports two different findings, which is why the negative
 * cases matter more here than anywhere else: the href pattern has to let
 * mailto:, tel:, anchors and unrendered placeholders through, or ordinary mails
 * collect findings they do not deserve.
 */
final class InsecureLinkCheckTest extends UnitTestCase
{
    private InsecureLinkCheck $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new InsecureLinkCheck();
    }

    /**
     * @param string[] $expected
     */
    private function assertIdentifiers(array $expected, string $html, string $text = ''): void
    {
        $results = $this->subject->check(
            CapturedMailFactory::create(['htmlBody' => $html, 'textBody' => $text])
        );

        self::assertSame($expected, array_map(static fn($r) => $r->identifier, $results));
    }

    #[Test]
    public function anAbsoluteHttpsLinkPasses(): void
    {
        $this->assertIdentifiers([], '<a href="https://example.com">Shop</a>');
    }

    #[Test]
    public function aRelativeLinkIsReported(): void
    {
        // A mail client has no base address, so this link goes nowhere.
        $this->assertIdentifiers(['relativeLink'], '<a href="/shop/item-4">Item</a>');
    }

    #[Test]
    public function anHttpLinkIsReported(): void
    {
        $this->assertIdentifiers(['insecureLink'], '<a href="http://example.com">Shop</a>');
    }

    #[Test]
    public function httpInThePlainTextPartIsReportedToo(): void
    {
        $this->assertIdentifiers(['insecureLink'], '<p>Hello</p>', 'Visit http://example.com');
    }

    #[Test]
    public function aRelativeAndAnInsecureLinkAreBothReported(): void
    {
        $this->assertIdentifiers(
            ['relativeLink', 'insecureLink'],
            '<a href="/shop">Shop</a> <a href="http://example.com">Home</a>'
        );
    }

    #[Test]
    public function mailtoTelAnchorsAndPlaceholdersAreNotRelativeLinks(): void
    {
        // Each of these would otherwise be reported on a perfectly good mail.
        $this->assertIdentifiers([], '<a href="mailto:info@example.com">Write</a>');
        $this->assertIdentifiers([], '<a href="tel:+4912345">Call</a>');
        $this->assertIdentifiers([], '<a href="#top">Top</a>');
        $this->assertIdentifiers([], '<a href="{unsubscribeUrl}">Unsubscribe</a>');
    }
}
