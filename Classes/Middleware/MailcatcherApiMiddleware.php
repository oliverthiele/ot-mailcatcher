<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\Middleware;

use OliverThiele\OtMailcatcher\Check\CheckRunner;
use OliverThiele\OtMailcatcher\Domain\Dto\CapturedMail;
use OliverThiele\OtMailcatcher\Domain\Repository\CapturedMailRepository;
use OliverThiele\OtMailcatcher\Service\MailcatcherState;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
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
    private const TOKEN_HEADER = 'X-Mailcatcher-Token';
    public const TOKEN_ENVIRONMENT_VARIABLE = 'MAILCATCHER_API_TOKEN';

    public function __construct(
        private readonly CapturedMailRepository $capturedMailRepository,
        private readonly CheckRunner $checkRunner,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if (!str_starts_with($path, self::ROUTE_PREFIX)) {
            return $handler->handle($request);
        }

        if (!$this->isAvailable($request)) {
            return new JsonResponse(['error' => 'Not found'], 404);
        }

        if ($request->getMethod() === 'DELETE') {
            return new JsonResponse(['deleted' => $this->capturedMailRepository->deleteAll()]);
        }

        if ($request->getMethod() !== 'GET') {
            return new JsonResponse(['error' => 'Method not allowed'], 405);
        }

        $identifier = trim(substr($path, strlen(self::ROUTE_PREFIX)), '/');
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
            if ($recipientFilter !== '' && !str_contains(strtolower($mail->getToAsString()), strtolower($recipientFilter))) {
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

    private function isAvailable(ServerRequestInterface $request): bool
    {
        $configuredToken = $this->readToken();
        if ($configuredToken === '' || !MailcatcherState::isActive()) {
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
