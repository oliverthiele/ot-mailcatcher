<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\Tests\Unit\Command;

use OliverThiele\OtMailcatcher\Command\PruneCommand;
use OliverThiele\OtMailcatcher\Command\ResendCommand;
use OliverThiele\OtMailcatcher\Domain\Repository\CapturedMailRepository;
use OliverThiele\OtMailcatcher\Service\ResendService;
use OliverThiele\OtMailcatcher\Tests\Unit\AbstractStorageTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Mail\MailerInterface;

/**
 * A mistyped number must stop the command. Read as "no limit", --limit=2O
 * sent every captured mail; read as the default, --days=3O pruned on a
 * schedule nobody asked for.
 */
final class CommandOptionValidationTest extends AbstractStorageTestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidNumberProvider(): array
    {
        return [
            'letter O instead of zero' => ['2O'],
            'word' => ['abc'],
            'zero' => ['0'],
            'negative' => ['-5'],
            'fraction' => ['1.5'],
        ];
    }

    #[Test]
    #[DataProvider('invalidNumberProvider')]
    public function resendRejectsAnInvalidLimit(string $limit): void
    {
        $this->switchCatcher(false);
        $this->placeCapturedMail('2026-08-25_100000-a.eml', "Subject: A\r\nFrom: a@example.com\r\nTo: b@example.com\r\n\r\nBody");
        $mailer = self::createMock(MailerInterface::class);
        $mailer->expects($this->never())->method('send');
        $tester = new CommandTester(new ResendCommand(new ResendService(new CapturedMailRepository(), $mailer)));

        self::assertSame(Command::INVALID, $tester->execute(['--limit' => $limit]));
    }

    #[Test]
    #[DataProvider('invalidNumberProvider')]
    public function pruneRejectsAnInvalidRetentionPeriod(string $days): void
    {
        $this->placeCapturedMail('2026-08-25_100000-a.eml');
        $tester = new CommandTester(new PruneCommand());

        self::assertSame(Command::INVALID, $tester->execute(['--days' => $days]));
        self::assertFileExists($this->storageDirectory . '/2026-08-25_100000-a.eml');
    }
}
