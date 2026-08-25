<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\Tests\Unit\Middleware;

use OliverThiele\OtMailcatcher\Check\CheckRunner;
use OliverThiele\OtMailcatcher\Domain\Repository\CapturedMailRepository;
use OliverThiele\OtMailcatcher\Middleware\MailcatcherApiMiddleware;
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

    private MailcatcherApiMiddleware $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $repository = self::createStub(CapturedMailRepository::class);
        $repository->method('findAll')->willReturn([CapturedMailFactory::create()]);
        $repository->method('findByIdentifier')->willReturn(CapturedMailFactory::create());

        $this->subject = new MailcatcherApiMiddleware($repository, new CheckRunner([]));

        $this->switchCatcher(true);
        $this->setToken(self::TOKEN);
    }

    protected function tearDown(): void
    {
        $this->setToken('');
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
}
