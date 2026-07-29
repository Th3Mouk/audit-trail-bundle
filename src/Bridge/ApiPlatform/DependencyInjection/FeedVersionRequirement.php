<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Bridge\ApiPlatform\DependencyInjection;

use Composer\InstalledVersions;
use Th3Mouk\AuditTrail\Exception\UnsupportedApiPlatformVersion;

/**
 * Refuses to build a feed on an API Platform that cannot paginate it.
 *
 * The feed's cursor covers `occurredAt` as well as `id`, because the identifier alone is only
 * chronological for captured rows — a backfill writes historical timestamps under fresh UUID v7
 * values. api-platform did not format a date cursor as ISO 8601 when building `hydra:next` until
 * 4.3.9 (api-platform/core#8241); before that it cast the value to a string and threw, so *every*
 * paginated response was a 500 in the one feature whose entire contract is paging.
 *
 * A composer `conflict` would express this too, but it would also block applications that install
 * this bundle and never enable the feed. This check fires only for the ones that do, at container
 * build time, and says what to do about it.
 */
final class FeedVersionRequirement
{
    public const string MINIMUM_VERSION = '4.3.9';

    private const string PACKAGE = 'api-platform/core';

    public static function assertInstalledVersionSupportsTheFeed(): void
    {
        if (!class_exists(InstalledVersions::class) || !InstalledVersions::isInstalled(self::PACKAGE)) {
            return;
        }

        self::assertSatisfiedBy(InstalledVersions::getVersion(self::PACKAGE));
    }

    /**
     * A version this cannot read is left alone rather than guessed at: a development checkout
     * (`dev-main`, `4.3.x-dev`) has no comparable number, and its consequences belong to whoever
     * chose it.
     */
    public static function assertSatisfiedBy(?string $installedVersion): void
    {
        if (null === $installedVersion || 1 !== preg_match('/^\d+\.\d+\.\d+/', $installedVersion)) {
            return;
        }

        if (version_compare($installedVersion, self::MINIMUM_VERSION, '<')) {
            throw UnsupportedApiPlatformVersion::forTheFeed($installedVersion, self::MINIMUM_VERSION);
        }
    }
}
