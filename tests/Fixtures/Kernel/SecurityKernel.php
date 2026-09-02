<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Fixtures\Kernel;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Th3Mouk\AuditTrail\AuditTrailBundle;

/**
 * The Doctrine-only setup plus SecurityBundle, registered the way a real application
 * registers it: alongside FrameworkBundle and DoctrineBundle, before AuditTrailBundle ever
 * sees the container.
 *
 * This is not an artificial ordering picked to provoke a bug — every ordering provokes it.
 * `AuditTrailExtension::load()` decides whether to register `SecurityTokenActorResolver`
 * with `ContainerBuilder::hasExtension('security')`, and that call is unconditionally
 * `false` from inside any extension's `load()`: `MergeExtensionConfigurationPass` compiles
 * each extension's configuration against an isolated, throwaway container that never has
 * any other bundle's extension registered on it. No bundle order and no bundle count
 * changes that. This kernel exists so an integration test can prove the resolver is missing
 * on unpatched code and present on patched code, against a real compiled container — the
 * one thing a test that calls `AuditTrailExtension::load()` directly cannot show, because
 * that shortcut never goes through `ContainerBuilder::compile()` and therefore never
 * reproduces the isolation that causes the bug.
 */
final class SecurityKernel extends TestKernel
{
    public const string USERNAME = 'grace.hopper';

    private const string PROVIDER = 'audit_trail_test_users';

    public static function isSupported(): bool
    {
        return class_exists(SecurityBundle::class);
    }

    /**
     * @return iterable<BundleInterface>
     */
    #[\Override]
    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new SecurityBundle();
        yield new DoctrineBundle();
        yield new AuditTrailBundle();
    }

    #[\Override]
    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        parent::configureContainer($container, $loader);

        $container->loadFromExtension('security', [
            'password_hashers' => [InMemoryUser::class => ['algorithm' => 'plaintext']],
            'providers' => [
                self::PROVIDER => [
                    'memory' => [
                        'users' => [
                            self::USERNAME => ['password' => 'irrelevant', 'roles' => ['ROLE_USER']],
                        ],
                    ],
                ],
            ],
            'firewalls' => [
                'main' => [
                    'pattern' => '^/',
                    'stateless' => true,
                    'provider' => self::PROVIDER,
                ],
            ],
        ]);
    }
}
