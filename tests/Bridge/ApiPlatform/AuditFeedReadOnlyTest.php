<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Bridge\ApiPlatform;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Th3Mouk\AuditTrail\Tests\Bridge\ApiPlatform\Case\AuditFeedTestCase;
use Th3Mouk\AuditTrail\Tests\Fixtures\Kernel\ApiPlatformKernel;

/**
 * A trail nobody can write to over HTTP.
 *
 * 405 rather than 404 on purpose: both URIs exist as GET-only routes, and answering "not found"
 * would suggest the trail is not there at all.
 */
final class AuditFeedReadOnlyTest extends AuditFeedTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function writeMethods(): iterable
    {
        yield 'POST' => ['POST'];
        yield 'PUT' => ['PUT'];
        yield 'PATCH' => ['PATCH'];
        yield 'DELETE' => ['DELETE'];
    }

    #[DataProvider('writeMethods')]
    public function testWritingToTheFeedIsRefusedAsAMethodProblem(string $method): void
    {
        $this->seedEntries(1);

        $response = $this->request($method, self::COLLECTION, ApiPlatformKernel::readerCredentials());

        self::assertSame(
            Response::HTTP_METHOD_NOT_ALLOWED,
            $response->getStatusCode(),
            \sprintf('%s on the feed should be refused as a method problem, not as a missing route.', $method),
        );
        self::assertStringContainsString('GET', (string) $response->headers->get('Allow'));
    }

    #[DataProvider('writeMethods')]
    public function testWritingToAnEntryIsRefusedAsAMethodProblem(string $method): void
    {
        $identifiers = $this->seedEntries(1);

        $response = $this->request(
            $method,
            \sprintf('%s/%s.jsonld', ApiPlatformKernel::ROUTE_PREFIX, $identifiers[0]),
            ApiPlatformKernel::readerCredentials(),
        );

        self::assertSame(
            Response::HTTP_METHOD_NOT_ALLOWED,
            $response->getStatusCode(),
            \sprintf('%s on an entry should be refused as a method problem, not as a missing route.', $method),
        );
    }

    public function testReadingAnEntryIsAllowed(): void
    {
        $identifiers = $this->seedEntries(1);

        $payload = $this->payloadOf($this->getAsReader(
            \sprintf('%s/%s.jsonld', ApiPlatformKernel::ROUTE_PREFIX, $identifiers[0]),
        ));

        self::assertSame($identifiers[0], basename((string) ($payload['@id'] ?? '')));
    }
}
