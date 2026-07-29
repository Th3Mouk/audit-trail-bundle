<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Bridge\ApiPlatform\Case;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Th3Mouk\AuditTrail\Entity\AuditLog;
use Th3Mouk\AuditTrail\Model\AuditEntry;
use Th3Mouk\AuditTrail\Tests\Case\AuditTrailKernelTestCase;
use Th3Mouk\AuditTrail\Tests\Fixtures\Kernel\ApiPlatformKernel;

/**
 * Skips the whole API Platform bridge suite, loudly, when the package is not installed.
 *
 * Rows are seeded straight through `AuditLog::fromEntry()` rather than through a capture: what
 * is under test here is the feed, and building the trail with the model the bundle exposes keeps
 * every scenario one line long.
 */
abstract class AuditFeedTestCase extends AuditTrailKernelTestCase
{
    protected const string COLLECTION = ApiPlatformKernel::ROUTE_PREFIX.'.jsonld';

    #[\Override]
    protected static function kernelUnderTest(): string
    {
        return ApiPlatformKernel::class;
    }

    protected function setUp(): void
    {
        if (!ApiPlatformKernel::isSupported()) {
            self::markTestSkipped('api-platform/core or symfony/security-bundle is not installed: the API Platform bridge suite cannot run.');
        }

        parent::setUp();
    }

    /**
     * @return list<string> the identifiers of the seeded rows, oldest first
     */
    protected function seed(AuditEntry ...$entries): array
    {
        $logs = array_map(AuditLog::fromEntry(...), $entries);

        $this->save(...$logs);

        return array_values(array_map(static fn (AuditLog $log): string => (string) $log->getId(), $logs));
    }

    /**
     * @return list<string>
     */
    protected function seedEntries(int $count): array
    {
        return $this->seed(...array_map(
            fn (int $number): AuditEntry => $this->anEntry(entityId: $number),
            range(1, $count),
        ));
    }

    /**
     * @param array<string, mixed> $query
     */
    protected static function uri(string $path, array $query = []): string
    {
        return [] === $query ? $path : $path.'?'.http_build_query($query);
    }

    protected function getAsReader(string $uri): Response
    {
        return $this->get($uri, ApiPlatformKernel::readerCredentials());
    }

    /**
     * @param array<string, string> $credentials
     */
    protected function request(string $method, string $uri, array $credentials = []): Response
    {
        return $this->handle(Request::create($uri, $method, server: $credentials));
    }

    /**
     * @return array<string, mixed>
     */
    protected function payloadOf(Response $response): array
    {
        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
            \sprintf('Unexpected response: %s', (string) $response->getContent()),
        );

        $payload = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    protected function collection(string $uri = self::COLLECTION): array
    {
        return $this->payloadOf($this->getAsReader($uri));
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<array<string, mixed>>
     */
    protected function membersOf(array $payload): array
    {
        $members = self::hydraValue($payload, 'member');
        self::assertIsArray($members, 'The collection payload carries no member list.');

        /** @var list<array<string, mixed>> $members */
        return array_values($members);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<string>
     */
    protected function idsOf(array $payload): array
    {
        return array_map(
            static fn (array $member): string => basename((string) ($member['@id'] ?? '')),
            $this->membersOf($payload),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function nextUriOf(array $payload): ?string
    {
        $view = self::hydraValue($payload, 'view');

        if (!\is_array($view)) {
            return null;
        }

        $next = self::hydraValue($view, 'next');

        return \is_string($next) ? $next : null;
    }

    /**
     * Reads a Hydra property whichever way the host configured `serializer.hydra_prefix`.
     *
     * @param array<string, mixed> $document
     */
    private static function hydraValue(array $document, string $key): mixed
    {
        return $document['hydra:'.$key] ?? $document[$key] ?? null;
    }
}
