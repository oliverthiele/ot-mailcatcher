<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\Tests\Unit\Mail;

use OliverThiele\OtMailcatcher\Domain\Repository\CapturedMailRepository;
use OliverThiele\OtMailcatcher\Mail\FileTransport;
use OliverThiele\OtMailcatcher\Service\MailcatcherState;
use OliverThiele\OtMailcatcher\Tests\Unit\AbstractStorageTestCase;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Mime\Email;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Mail\TransportFactory;
use TYPO3\CMS\Core\Resource\Security\FileNameValidator;

/**
 * From the mail configuration through TYPO3's own TransportFactory to the file
 * and back through the repository — the path a real mail takes, including the
 * two traps a hand-written .eml fixture cannot show: a configured DSN that wins
 * over the transport class, and the Bcc header Symfony strips before writing.
 */
final class FileTransportRoundTripTest extends AbstractStorageTestCase
{
    private mixed $mailConfigurationBackup = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mailConfigurationBackup = $GLOBALS['TYPO3_CONF_VARS']['MAIL'] ?? null;
        $GLOBALS['TYPO3_CONF_VARS']['MAIL'] = [
            'transport' => 'smtp',
            'dsn' => 'smtp://mail.example.com:25',
            'transport_spool_type' => 'file',
        ];
        $this->switchCatcher(true);
        MailcatcherState::wireMailTransport();
    }

    protected function tearDown(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['MAIL'] = $this->mailConfigurationBackup;
        parent::tearDown();
    }

    #[Test]
    public function theCoreTransportFactoryResolvesToTheCatcherDespiteADsn(): void
    {
        self::assertInstanceOf(FileTransport::class, $this->transport());
    }

    #[Test]
    public function aBlindCopyRecipientSurvivesCapturing(): void
    {
        $this->transport()->send(
            (new Email())
                ->from('noreply@example.com')
                ->to('customer@elsewhere.test')
                ->bcc('archive@example.com')
                ->subject('Order confirmation')
                ->text('Body')
        );

        $files = glob($this->storageDirectory . '/*.eml') ?: [];
        self::assertCount(1, $files);
        self::assertStringNotContainsString('archive@example.com', (string)file_get_contents($files[0]), 'Symfony strips Bcc from the message itself.');
        self::assertFileExists($files[0] . FileTransport::ENVELOPE_SUFFIX);

        $mail = (new CapturedMailRepository())->findAll()[0];
        self::assertSame(['archive@example.com'], $mail->bcc);
        self::assertSame(['customer@elsewhere.test', 'archive@example.com'], $mail->getDeliveryRecipients());
        self::assertSame('noreply@example.com', $mail->getDeliverySender());
    }

    #[Test]
    public function twoMailsOfOneRequestLandInTwoFiles(): void
    {
        $transport = $this->transport();
        foreach (['receiver', 'sender'] as $role) {
            $transport->send((new Email())->from('noreply@example.com')->to('customer@elsewhere.test')->subject($role)->text('Body'));
        }

        self::assertCount(2, glob($this->storageDirectory . '/*.eml') ?: []);
        self::assertSame([], glob($this->storageDirectory . '/.*.tmp') ?: [], 'No temporary file is left behind.');
    }

    private function transport(): \Symfony\Component\Mailer\Transport\TransportInterface
    {
        $factory = new TransportFactory(new EventDispatcher(), new LogManager(), new NullLogger(), new FileNameValidator());

        return $factory->get($GLOBALS['TYPO3_CONF_VARS']['MAIL']);
    }
}
