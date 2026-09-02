<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Th3Mouk\AuditTrail\Actor\SecurityTokenActorResolver;
use Th3Mouk\AuditTrail\Enum\AuditAction;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\Post;
use Th3Mouk\AuditTrail\Tests\Fixtures\Kernel\SecurityKernel;

/**
 * The default actor resolver, in a container shaped like a real application's: FrameworkBundle,
 * SecurityBundle and DoctrineBundle registered before AuditTrailBundle ever sees the container.
 *
 * `SecurityKernel` boots exactly that way on purpose. `AuditTrailExtension::load()` used to
 * decide whether to register `SecurityTokenActorResolver` with
 * `ContainerBuilder::hasExtension('security')` — a call that is unconditionally `false` from
 * inside any extension's own `load()`, because Symfony compiles every extension's configuration
 * against an isolated, throwaway container (`MergeExtensionConfigurationPass`) that never has any
 * other bundle's extension registered on it. The resolver was therefore never registered in any
 * application, regardless of bundle order — which is exactly what the first test below pins.
 *
 * The second test proves the fix delivers the feature, not just the registration: a token placed
 * in `security.token_storage` — the same thing an authenticated request leaves behind — has to
 * reach the audit row as `actor_id`.
 */
#[CoversNothing]
final class SecurityActorResolutionTest extends IntegrationTestCase
{
    #[\Override]
    protected static function kernelUnderTest(): string
    {
        return SecurityKernel::class;
    }

    protected function setUp(): void
    {
        if (!SecurityKernel::isSupported()) {
            self::markTestSkipped('symfony/security-bundle is not installed: this kernel cannot boot.');
        }

        parent::setUp();
    }

    /**
     * `IntegrationTestCase::tearDown()` drops the schema unconditionally, which needs a booted
     * kernel — exactly what a skipped `setUp()` never produces. Without this guard, a skip turns
     * into an error raised from teardown instead: the "without optional dependencies" CI job
     * genuinely removes symfony/security-bundle and runs this suite, so this is not a hypothetical.
     */
    #[\Override]
    protected function tearDown(): void
    {
        if (!SecurityKernel::isSupported()) {
            return;
        }

        parent::tearDown();
    }

    public function testTheDefaultResolverIsRegisteredWheneverSecurityBundleIsInstalled(): void
    {
        self::assertTrue(
            self::getContainer()->has(SecurityTokenActorResolver::class),
            'SecurityTokenActorResolver must enter the container whenever symfony/security-bundle is '
            .'registered. It must not depend on ContainerBuilder::hasExtension("security"), which is '
            .'unconditionally false from inside AuditTrailExtension::load().',
        );
    }

    public function testAFlushWhileATokenIsStoredIsAttributedToItsIdentifier(): void
    {
        $tokenStorage = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokenStorage);

        $user = new InMemoryUser(SecurityKernel::USERNAME, null);
        $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

        $this->save(new Post('Analytical Engine'));

        $entry = $this->assertOneEntry(AuditAction::Create, Post::class);
        $this->assertActorIs($entry, SecurityKernel::USERNAME);
    }
}
