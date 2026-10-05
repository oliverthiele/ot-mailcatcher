<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\Tests\Unit\Service;

use OliverThiele\OtMailcatcher\Mail\FileTransport;
use OliverThiele\OtMailcatcher\Mail\RefusingTransport;
use OliverThiele\OtMailcatcher\Service\MailcatcherState;
use OliverThiele\OtMailcatcher\Tests\Unit\AbstractStorageTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The wiring decides whether a mail leaves the machine. TransportFactory
 * ignores the transport class as soon as a DSN or a spool type is configured,
 * so a catcher that only set the transport reported "capturing" while every
 * mail went out over SMTP.
 */
final class MailcatcherStateTest extends AbstractStorageTestCase
{
    private const SMTP_DSN = 'smtp://mail.example.com:25';

    private mixed $mailConfigurationBackup = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mailConfigurationBackup = $GLOBALS['TYPO3_CONF_VARS']['MAIL'] ?? null;
        putenv(MailcatcherState::ALLOW_ENVIRONMENT_VARIABLE);
        unset($_ENV[MailcatcherState::ALLOW_ENVIRONMENT_VARIABLE]);
    }

    protected function tearDown(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['MAIL'] = $this->mailConfigurationBackup;
        parent::tearDown();
    }

    #[Test]
    public function wiringClearsADsnAndASpoolThatWouldWinOverTheTransport(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['MAIL'] = ['transport' => 'smtp', 'dsn' => self::SMTP_DSN, 'transport_spool_type' => 'file'];
        $this->switchCatcher(true);

        MailcatcherState::wireMailTransport();

        self::assertSame(FileTransport::class, $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport']);
        self::assertSame('', $GLOBALS['TYPO3_CONF_VARS']['MAIL']['dsn']);
        self::assertSame('', $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_spool_type']);
        self::assertTrue(MailcatcherState::isWired());
    }

    #[Test]
    public function theTransportAloneIsNotWiredWhileADsnIsSet(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['MAIL'] = ['transport' => FileTransport::class, 'dsn' => self::SMTP_DSN];

        self::assertFalse(MailcatcherState::isWired());
    }

    #[Test]
    public function theTransportAloneIsNotWiredWhileASpoolIsSet(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['MAIL'] = ['transport' => FileTransport::class, 'transport_spool_type' => 'file'];

        self::assertFalse(MailcatcherState::isWired());
    }

    #[Test]
    public function aCatcherThatMayNotRunHereRefusesAndClearsTheDsnToo(): void
    {
        $this->switchApplicationContext('Production');
        $GLOBALS['TYPO3_CONF_VARS']['MAIL'] = ['transport' => 'smtp', 'dsn' => self::SMTP_DSN];
        $this->switchCatcher(true);

        MailcatcherState::wireMailTransport();

        self::assertSame(RefusingTransport::class, $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport']);
        self::assertSame('', $GLOBALS['TYPO3_CONF_VARS']['MAIL']['dsn']);
    }

    #[Test]
    public function aSwitchedOffCatcherLeavesTheMailConfigurationAlone(): void
    {
        $configuration = ['transport' => 'smtp', 'dsn' => self::SMTP_DSN, 'transport_spool_type' => 'file'];
        $GLOBALS['TYPO3_CONF_VARS']['MAIL'] = $configuration;
        $this->switchCatcher(false);

        MailcatcherState::wireMailTransport();

        self::assertSame($configuration, $GLOBALS['TYPO3_CONF_VARS']['MAIL']);
    }

    #[Test]
    public function anUnreadableStateFileCountsAsSwitchedOn(): void
    {
        file_put_contents($this->storageDirectory . '/state.json', '{"enabled": tru');

        self::assertTrue(MailcatcherState::isEnabled());
    }

    #[Test]
    public function aMissingStateFileMeansSwitchedOff(): void
    {
        self::assertFalse(MailcatcherState::isEnabled());
    }

    #[Test]
    public function theStateIsWrittenWithoutLeavingATemporaryFile(): void
    {
        MailcatcherState::setEnabled(true);
        self::assertTrue(MailcatcherState::isEnabled());

        MailcatcherState::setEnabled(false);
        self::assertFalse(MailcatcherState::isEnabled());
        self::assertSame([], glob($this->storageDirectory . '/*.tmp'));
    }
}
