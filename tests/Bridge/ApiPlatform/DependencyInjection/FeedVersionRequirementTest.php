<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Bridge\ApiPlatform\DependencyInjection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Th3Mouk\AuditTrail\Bridge\ApiPlatform\DependencyInjection\FeedVersionRequirement;
use Th3Mouk\AuditTrail\Exception\UnsupportedApiPlatformVersion;

/**
 * The version floor exists because a feed that cannot paginate is not a feed. It is asserted with
 * injected version strings rather than by installing an old api-platform, which is the only way to
 * pin behaviour on versions this suite will never run against.
 */
#[CoversClass(FeedVersionRequirement::class)]
#[CoversClass(UnsupportedApiPlatformVersion::class)]
final class FeedVersionRequirementTest extends TestCase
{
    #[DataProvider('versionsTooOld')]
    public function testAVersionThatCannotFormatADateCursorIsRefused(string $installed): void
    {
        $this->expectException(UnsupportedApiPlatformVersion::class);
        $this->expectExceptionMessage($installed);
        $this->expectExceptionMessage('audit_trail.bridges.api_platform.enabled');

        FeedVersionRequirement::assertSatisfiedBy($installed);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function versionsTooOld(): iterable
    {
        yield 'the previous minor' => ['4.2.25.0'];
        yield 'the patch right before the fix' => ['4.3.8.0'];
        yield 'an ancient major' => ['3.4.0.0'];
    }

    #[DataProvider('versionsGoodEnough')]
    public function testAVersionThatCanIsAccepted(string $installed): void
    {
        $this->expectNotToPerformAssertions();

        FeedVersionRequirement::assertSatisfiedBy($installed);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function versionsGoodEnough(): iterable
    {
        yield 'the version that fixed it' => ['4.3.9.0'];
        yield 'a later patch' => ['4.3.17.0'];
        yield 'a later minor' => ['4.4.0.0'];
        yield 'a later major' => ['5.0.0.0'];
    }

    /**
     * Refusing to guess is the point: a development checkout has no comparable number, and turning
     * that into a build failure would break the very people testing an unreleased fix.
     */
    #[DataProvider('versionsThatCannotBeRead')]
    public function testAVersionWithNoNumberToCompareIsLeftAlone(?string $installed): void
    {
        $this->expectNotToPerformAssertions();

        FeedVersionRequirement::assertSatisfiedBy($installed);
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function versionsThatCannotBeRead(): iterable
    {
        yield 'a branch alias' => ['4.3.x-dev'];
        yield 'a development branch' => ['dev-main'];
        yield 'nothing at all' => [null];
    }

    public function testTheInstalledVersionOfThisSuitePassesItsOwnFloor(): void
    {
        $this->expectNotToPerformAssertions();

        FeedVersionRequirement::assertInstalledVersionSupportsTheFeed();
    }
}
