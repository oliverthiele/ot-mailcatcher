<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\Tests\Unit\Middleware;

use OliverThiele\OtMailcatcher\Check\CheckRunner;
use OliverThiele\OtMailcatcher\Domain\Repository\CapturedMailRepository;
use OliverThiele\OtMailcatcher\Mail\FileTransport;
use OliverThiele\OtMailcatcher\Middleware\MailcatcherApiMiddleware;
use OliverThiele\OtMailcatcher\Service\ConfigurationValidator;
use OliverThiele\OtMailcatcher\Tests\Unit\AbstractStorageTestCase;
use OliverThiele\OtMailcatcher\Tests\Unit\Check\CapturedMailFactory;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\ServerRequest;

/**
 * The API hands out the full content of every captured mail — subjects, bodies,
 * recipients, and any password reset link that was caught. Unlike the backend
 * module it is not behind an administrator session; the token is the only thing
 * between it and the internet.
 *
 * These tests exist for that one property. Note the contrast in each pair: the
 * positive case proves the request would otherwise succeed, so a 404 cannot be
 * mistaken for "closed" when it merely means "nothing here".
 */
final class MailcatcherApiMiddlewareTest extends AbstractStorageTestCase
{
    private const TOKEN = 'a-token-long-enough-to-be-realistic';
    private const PATH = '/_mailcatcher/api/messages';
    private const STATUS_PATH = '/_mailcatcher/api/status';

    private MailcatcherApiMiddleware $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $repository = self::createStub(CapturedMailRepository::class);
        $repository->method('findAll')->willReturn([CapturedMailFactory::create()]);
        $repository->method('findByIdentifier')->willReturn(CapturedMailFactory::create());

        $this->subject = new MailcatcherApiMiddleware(
            $repository,
            new CheckRunner([]),
            new ConfigurationValidator(),
        );

        $this->switchCatcher(true);
        $this->setToken(self::TOKEN);
    }

    protected function tearDown(): void
    {
        $this->setToken('');
        unset($GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport']);
        parent::tearDown();
    }

    private function setToken(string $token): void
    {
        putenv('MAILCATCHER_API_TOKEN' . ($token === '' ? '' : '=' . $token));
        if ($token === '') {
            unset($_ENV['MAILCATCHER_API_TOKEN']);
            return;
        }
        $_ENV['MAILCATCHER_API_TOKEN'] = $token;
    }

    private function request(string $path = self::PATH, ?string $token = self::TOKEN): ServerRequestInterface
    {
        $request = new ServerRequest($path, 'GET');

        return $token === null ? $request : $request->withHeader('X-Mailcatcher-Token', $token);
    }

    private function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new JsonResponse(['passedThrough' => true]);
            }
        };
    }

    private function statusOf(ServerRequestInterface $request): int
    {
        return $this->subject->process($request, $this->handler())->getStatusCode();
    }

    #[Test]
    public function aValidTokenIsAnswered(): void
    {
        // The reference point: without this, every 404 below would prove nothing.
        self::assertSame(200, $this->statusOf($this->request()));
    }

    #[Test]
    public function withoutATokenConfiguredTheRouteIsClosed(): void
    {
        // The default state of every installation that never sets the variable.
        $this->setToken('');

        self::assertSame(404, $this->statusOf($this->request(token: null)));
    }

    #[Test]
    public function aRequestWithoutTheHeaderIsRefused(): void
    {
        self::assertSame(404, $this->statusOf($this->request(token: null)));
    }

    #[Test]
    public function aWrongTokenIsRefused(): void
    {
        self::assertSame(404, $this->statusOf($this->request(token: 'not-the-token')));
    }

    #[Test]
    public function aTokenThatIsMerelyAPrefixIsRefused(): void
    {
        // hash_equals compares the whole string; a prefix must not be enough.
        self::assertSame(404, $this->statusOf($this->request(token: substr(self::TOKEN, 0, 10))));
    }

    #[Test]
    public function whileTheCatcherIsOffTheRouteIsClosed(): void
    {
        // Even with the correct token: nothing is being captured, so the API has
        // no business answering.
        $this->switchCatcher(false);

        self::assertSame(404, $this->statusOf($this->request()));
    }

    #[Test]
    public function anUnrelatedPathIsPassedOnUntouched(): void
    {
        $response = $this->subject->process($this->request('/some/page', token: null), $this->handler());

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('passedThrough', (string)$response->getBody());
    }

    #[Test]
    public function anUnsupportedMethodIsRejected(): void
    {
        $request = $this->request()->withMethod('PUT');

        self::assertSame(405, $this->statusOf($request));
    }

    /**
     * @return array<string, mixed>
     */
    private function statusPayload(): array
    {
        $response = $this->subject->process($this->request(self::STATUS_PATH), $this->handler());

        self::assertSame(200, $response->getStatusCode());

        $payload = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload;
    }

    private function wireTransport(bool $wired): void
    {
        if ($wired) {
            $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport'] = FileTransport::class;
            return;
        }

        unset($GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport']);
    }

    #[Test]
    public function theStatusRouteAnswersWhileTheCatcherIsOff(): void
    {
        // The property the whole route exists for. A caller asks precisely
        // because it does not know, and "off" is the answer it most needs — the
        // messages route answers 404 in this situation and cannot say it.
        $this->switchCatcher(false);
        $this->wireTransport(false);

        $payload = $this->statusPayload();

        self::assertSame('inactive', $payload['status']);
        self::assertTrue($payload['mailIsBeingSent']);
        self::assertFalse($payload['enabled']);
    }

    #[Test]
    public function theStatusRouteReportsCaptureWhenTheCatcherIsOnAndWiredUp(): void
    {
        $this->switchCatcher(true);
        $this->wireTransport(true);

        $payload = $this->statusPayload();

        self::assertSame('active', $payload['status']);
        self::assertFalse($payload['mailIsBeingSent'], 'nothing leaves the machine in this state');
        self::assertTrue($payload['wired']);
    }

    #[Test]
    public function theStatusRouteReportsMailGoingOutWhenTheTransportWasNeverWiredUp(): void
    {
        // The dangerous one, and the reason a caller cannot settle for "is the
        // switch on": the backend reports a running catcher while every mail is
        // delivered as usual. isActive() alone would say yes here.
        $this->switchCatcher(true);
        $this->wireTransport(false);

        $payload = $this->statusPayload();

        self::assertSame('notTakingEffect', $payload['status']);
        self::assertTrue($payload['mailIsBeingSent']);
        self::assertTrue($payload['enabled']);
        self::assertFalse($payload['wired']);
    }

    #[Test]
    public function theStatusRouteNeedsATokenAsWell(): void
    {
        // It describes the configuration, so it is not for anonymous eyes —
        // even though it never exposes a mail.
        self::assertSame(404, $this->statusOf($this->request(self::STATUS_PATH, token: null)));
    }

    #[Test]
    public function theStatusRouteRefusesAWrongToken(): void
    {
        self::assertSame(
            404,
            $this->statusOf($this->request(self::STATUS_PATH, token: 'not-the-token')),
        );
    }

    #[Test]
    public function theStatusRouteRejectsAnUnsupportedMethod(): void
    {
        $request = $this->request(self::STATUS_PATH)->withMethod('DELETE');

        self::assertSame(405, $this->statusOf($request));
    }
}
