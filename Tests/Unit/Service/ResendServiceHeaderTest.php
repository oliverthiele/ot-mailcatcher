<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\Tests\Unit\Service;

use OliverThiele\OtMailcatcher\Service\ResendService;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * The context header records the request a mail came from — URL with query
 * string, page id, form identifier. It is a debugging aid for the module and
 * must not travel to a real recipient when a captured mail is sent later.
 */
final class ResendServiceHeaderTest extends UnitTestCase
{
    #[Test]
    public function theHeaderIsRemovedIncludingItsFoldedLines(): void
    {
        $source = "Subject: Order\r\n"
            . "X-Mailcatcher-Context: https://www.example.com/contact?token=secret\r\n"
            . " | page=12 | form=contact\r\n"
            . "From: noreply@example.com\r\n"
            . "\r\n"
            . "X-Mailcatcher-Context: in the body stays\r\n";

        self::assertSame(
            "Subject: Order\r\nFrom: noreply@example.com\r\n\r\nX-Mailcatcher-Context: in the body stays\r\n",
            ResendService::removeHeader($source, 'X-Mailcatcher-Context')
        );
    }

    #[Test]
    public function aMessageWithBareLineFeedsKeepsItsLineEndings(): void
    {
        $source = "Subject: Order\nX-Mailcatcher-Context: CLI\nFrom: noreply@example.com\n\nBody";

        self::assertSame(
            "Subject: Order\nFrom: noreply@example.com\n\nBody",
            ResendService::removeHeader($source, 'X-Mailcatcher-Context')
        );
    }

    #[Test]
    public function aMessageWithoutTheHeaderIsUnchanged(): void
    {
        $source = "Subject: Order\r\nFrom: noreply@example.com\r\n\r\nBody";

        self::assertSame($source, ResendService::removeHeader($source, 'X-Mailcatcher-Context'));
    }
}
