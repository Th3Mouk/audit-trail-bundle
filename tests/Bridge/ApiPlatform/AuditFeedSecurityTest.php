<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Bridge\ApiPlatform;

use Symfony\Component\HttpFoundation\Response;
use Th3Mouk\AuditTrail\Tests\Bridge\ApiPlatform\Case\AuditFeedTestCase;
use Th3Mouk\AuditTrail\Tests\Fixtures\Kernel\ApiPlatformKernel;

/**
 * The host owns the permission name; the bundle only carries it.
 *
 * `ROLE_AUDIT_READER` exists in the fixture application, nowhere in the bundle. Configure no
 * expression and the feed carries no security attribute at all — which is a decision, not an
 * oversight, and is asserted as such.
 */
final class AuditFeedSecurityTest extends AuditFeedTestCase
{
    private const array WITHOUT_SECURITY = [
        'bridges' => ['api_platform' => ['security' => null]],
    ];

    public function testAnAuthorisedReaderGetsTheFeed(): void
    {
        $identifiers = $this->seedEntries(1);

        $response = $this->get(self::COLLECTION, ApiPlatformKernel::readerCredentials());

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame($identifiers, $this->idsOf($this->payloadOf($response)));
    }

    public function testAnAuthenticatedStrangerIsRefusedTheCollection(): void
    {
        $this->seedEntries(1);

        $response = $this->get(self::COLLECTION, ApiPlatformKernel::strangerCredentials());

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function testAnAuthenticatedStrangerIsRefusedAnEntry(): void
    {
        $identifiers = $this->seedEntries(1);

        $response = $this->get(
            \sprintf('%s/%s.jsonld', ApiPlatformKernel::ROUTE_PREFIX, $identifiers[0]),
            ApiPlatformKernel::strangerCredentials(),
        );

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function testWithNoExpressionConfiguredTheBundleImposesNothing(): void
    {
        $this->bootWith(self::WITHOUT_SECURITY);
        $identifiers = $this->seedEntries(1);

        $response = $this->get(self::COLLECTION);

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
            'With no security expression configured the feed must be exactly as protected as the host made it.',
        );
        self::assertSame($identifiers, $this->idsOf($this->payloadOf($response)));
    }
}
