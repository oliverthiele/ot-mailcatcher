<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\Middleware;

use OliverThiele\OtMailcatcher\Check\CheckRunner;
use OliverThiele\OtMailcatcher\Domain\Dto\CapturedMail;
use OliverThiele\OtMailcatcher\Domain\Repository\CapturedMailRepository;
use OliverThiele\OtMailcatcher\Service\ConfigurationValidator;
use OliverThiele\OtMailcatcher\Service\MailcatcherState;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * Read-only HTTP access to the captured mails, for end-to-end tests.
 *
 * The point is not merely to expose the mails: the same rules that produce the
 * editor-facing findings in the backend module are returned here as stable
 * identifiers, so a Playwright test can assert on them and fail the build on a
 * mail misconfiguration.
 *
 * Locked three ways — the catcher must be active, the environment must permit
 * it, and the request must carry the configured token. Without a configured
 * token the route answers 404 rather than 403: an endpoint that does not exist
 * reveals nothing about what it would have guarded.
 */
final class MailcatcherApiMiddleware implements MiddlewareInterface
{
    private const ROUTE_PREFIX = '/_mailcatcher/api/messages';
    private const STATUS_ROUTE = '/_mailcatcher/api/status';
    private const TOKEN_HEADER = 'X-Mailcatcher-Token';
    public const TOKEN_ENVIRONMENT_VARIABLE = 'MAILCATCHER_API_TOKEN';

    public function __construct(
        private readonly CapturedMailRepository $capturedMailRepository,
        private readonly CheckRunner $checkRunner,
        private readonly ConfigurationValidator $configurationValidator,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        if ($path === self::STATUS_ROUTE) {
            return self::withoutCaching($this->statusResponse($request));
        }

        if (!str_starts_with($path, self::ROUTE_PREFIX)) {
            return $handler->handle($request);
        }

        return self::withoutCaching($this->messagesResponse($request, $path));
    }

    /**
     * Every answer of this API describes mail or configuration. A cache in front
     * of a staging system — Varnish, a CDN — would otherwise store a 200 for a
     * request that carries neither a cookie nor an Authorization header, and
     * serve it to the next caller without the token.
     */
    private static function withoutCaching(ResponseInterface $response): ResponseInterface
    {
        return $response
            ->withHeader('Cache-Control', 'no-store, private')
            ->withHeader('Vary', self::TOKEN_HEADER);
    }

    private function messagesResponse(ServerRequestInterface $request, string $path): ResponseInterface
    {
        if (!$this->isAvailable($request)) {
            return new JsonResponse(['error' => 'Not found'], 404);
        }

        $identifier = trim(substr($path, strlen(self::ROUTE_PREFIX)), '/');

        if ($request->getMethod() === 'DELETE') {
            if ($identifier === '') {
                // Unlocked Production holds real mail that nobody has received
                // yet. The module asks before deleting all of it and the prune
                // command needs --force there; a test teardown must not be the
                // shortcut around both. Deleting one mail stays possible: a
                // form test after a go-live removes exactly the mails it found.
                if (Environment::getContext()->isProduction()) {
                    return new JsonResponse(['error' => 'Deleting all mails is not available in a Production context'], 403);
                }

                return new JsonResponse(['deleted' => $this->capturedMailRepository->deleteAll()]);
            }

            return $this->capturedMailRepository->delete($identifier)
                ? new JsonResponse(['deleted' => 1])
                : new JsonResponse(['error' => 'Not found'], 404);
        }

        if ($request->getMethod() !== 'GET') {
            return new JsonResponse(['error' => 'Method not allowed'], 405);
        }

        if ($identifier !== '') {
            $mail = $this->capturedMailRepository->findByIdentifier($identifier);
            if ($mail === null) {
                return new JsonResponse(['error' => 'Not found'], 404);
            }

            return new JsonResponse($this->serialize($mail, true));
        }

        return new JsonResponse(['messages' => $this->collectMessages($request)]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function collectMessages(ServerRequestInterface $request): array
    {
        $queryParameters = $request->getQueryParams();
        $recipientFilter = $this->stringParameter($queryParameters, 'to');
        $subjectFilter = $this->stringParameter($queryParameters, 'subject');

        $messages = [];
        foreach ($this->capturedMailRepository->findAll() as $mail) {
            // Any recipient — To, Cc and Bcc — not only To: a form that sends a
            // copy to the visitor in Cc must be findable by that address.
            if ($recipientFilter !== '' && !str_contains(strtolower(implode(', ', [...$mail->to, ...$mail->cc, ...$mail->bcc])), strtolower($recipientFilter))) {
                continue;
            }
            if ($subjectFilter !== '' && !str_contains(strtolower($mail->subject), strtolower($subjectFilter))) {
                continue;
            }

            $messages[] = $this->serialize($mail, false);
        }

        return $messages;
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(CapturedMail $mail, bool $withBody): array
    {
        $results = $this->checkRunner->run($mail);

        $data = [
            'identifier' => $mail->identifier,
            'subject' => $mail->subject,
            'from' => $mail->from,
            'to' => $mail->to,
            'cc' => $mail->cc,
            'bcc' => $mail->bcc,
            'replyTo' => $mail->replyTo,
            'date' => $mail->date?->format(\DATE_ATOM),
            'size' => $mail->size,
            'context' => $mail->context,
            'hasHtmlPart' => $mail->hasHtmlPart,
            'hasTextPart' => $mail->hasTextPart,
            'checks' => array_map(
                static fn($result): array => [
                    'identifier' => $result->identifier,
                    'severity' => $result->severity->value,
                ],
                $results
            ),
        ];

        if ($withBody) {
            $data['text'] = $mail->textBody;
            $data['html'] = $mail->htmlBody;
            $data['headers'] = $mail->headers;
            $data['attachments'] = array_map(
                static fn($attachment): array => [
                    'fileName' => $attachment->fileName,
                    'mimeType' => $attachment->mimeType,
                    'size' => $attachment->size,
                ],
                $mail->attachments
            );
        }

        return $data;
    }

    /**
     * The catcher's own state, for a caller that has to decide whether sending
     * is safe before it triggers a mail.
     *
     * Deliberately **not** behind isAvailable(). That requires an active
     * catcher, and a status route which only answers while the catcher is on can
     * never report the two states a caller most needs to hear: that it is off,
     * or that it is on but not wired up and mail is going out regardless. The
     * token stays mandatory — this describes the configuration, so it is not for
     * anonymous eyes.
     *
     * `mailIsBeingSent` is the field to branch on. The individual flags are
     * there to make a failure message say *why*, not to be recombined by the
     * caller — that logic lives in ConfigurationValidator and should stay in one
     * place.
     */
    private function statusResponse(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->hasValidToken($request)) {
            return new JsonResponse(['error' => 'Not found'], 404);
        }

        if ($request->getMethod() !== 'GET') {
            return new JsonResponse(['error' => 'Method not allowed'], 405);
        }

        $status = $this->configurationValidator->getStatus();

        return new JsonResponse([
            'status' => $status->value,
            'mailIsBeingSent' => $status->isMailBeingSent(),
            'enabled' => MailcatcherState::isEnabled(),
            'allowed' => MailcatcherState::isAllowed(),
            'wired' => MailcatcherState::isWired(),
            'enabledSince' => MailcatcherState::getEnabledSince()?->format(\DateTimeInterface::ATOM),
        ]);
    }

    private function isAvailable(ServerRequestInterface $request): bool
    {
        if (!MailcatcherState::isActive()) {
            return false;
        }

        return $this->hasValidToken($request);
    }

    private function hasValidToken(ServerRequestInterface $request): bool
    {
        $configuredToken = $this->readToken();
        if ($configuredToken === '') {
            return false;
        }

        $providedToken = $request->getHeaderLine(self::TOKEN_HEADER);

        return $providedToken !== '' && hash_equals($configuredToken, $providedToken);
    }

    private function readToken(): string
    {
        return MailcatcherState::readEnvironmentVariable(self::TOKEN_ENVIRONMENT_VARIABLE);
    }

    /**
     * @param array<mixed> $parameters
     */
    private function stringParameter(array $parameters, string $name): string
    {
        $value = $parameters[$name] ?? null;

        return is_scalar($value) ? (string)$value : '';
    }
}
