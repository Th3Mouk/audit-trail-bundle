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
    /**
     * `grants` is emptied as well as `public` being set: the modes are mutually exclusive, and the
     * kernel's own configuration is merged recursively, so leaving the default grants in place would
     * declare two of them — which the configuration rejects, by design.
     */
    private const array PUBLIC_FEED = [
        'bridges' => ['api_platform' => ['access' => ['grants' => [], 'public' => true]]],
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

    public function testAnExplicitlyPublicFeedImposesNothing(): void
    {
        $this->bootWith(self::PUBLIC_FEED);
        $identifiers = $this->seedEntries(1);

        $response = $this->get(self::COLLECTION);

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
            'With access.public set the feed must be exactly as protected as the host made it, and no more.',
        );
        self::assertSame($identifiers, $this->idsOf($this->payloadOf($response)));
    }
}
