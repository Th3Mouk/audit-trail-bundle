<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Bridge\ApiPlatform;

/**
 * Host-supplied shape of the audit feed, mirroring `audit_trail.bridges.api_platform`.
 *
 * `security` is deliberately nullable: the bundle never invents a permission name, so a null
 * expression means the resource metadata carries no security attribute at all and the host
 * decides how the feed is protected (firewall, access_control, its own decorator).
 */
final readonly class AuditFeedOptions
{
    public const string DEFAULT_ROUTE_PREFIX = '/audit-logs';
    public const int DEFAULT_ITEMS_PER_PAGE = 50;
    public const int DEFAULT_MAX_ITEMS_PER_PAGE = 200;

    private function __construct(
        public string $routePrefix,
        public int $itemsPerPage,
        public int $maxItemsPerPage,
        public ?string $security,
    ) {
    }

    public static function of(
        ?string $routePrefix = null,
        ?int $itemsPerPage = null,
        ?int $maxItemsPerPage = null,
        ?string $security = null,
    ): self {
        $itemsPerPage = max(1, $itemsPerPage ?? self::DEFAULT_ITEMS_PER_PAGE);
        $maxItemsPerPage = max($itemsPerPage, $maxItemsPerPage ?? self::DEFAULT_MAX_ITEMS_PER_PAGE);

        return new self(
            self::normalizeRoutePrefix($routePrefix),
            $itemsPerPage,
            $maxItemsPerPage,
            '' === $security ? null : $security,
        );
    }

    public function collectionUriTemplate(): string
    {
        return $this->routePrefix.'{._format}';
    }

    public function itemUriTemplate(): string
    {
        return $this->routePrefix.'/{id}{._format}';
    }

    private static function normalizeRoutePrefix(?string $routePrefix): string
    {
        $normalized = rtrim(trim((string) $routePrefix), '/');

        if ('' === $normalized) {
            return self::DEFAULT_ROUTE_PREFIX;
        }

        return str_starts_with($normalized, '/') ? $normalized : '/'.$normalized;
    }
}
