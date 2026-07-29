<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Fixtures\Kernel;

use ApiPlatform\Symfony\Bundle\ApiPlatformBundle;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * The Doctrine-only setup plus API Platform, over HTTP, behind a security expression.
 *
 * The expression is supplied by this fixture application, never by the bundle: the bundle
 * has no idea what roles exist here. Two users make the point provable — one that the
 * expression admits, one it does not.
 */
final class ApiPlatformKernel extends TestKernel
{
    public const string SECURITY_EXPRESSION = "is_granted('ROLE_AUDIT_READER')";

    public const string ROUTE_PREFIX = '/audit_logs';

    public const string READER_USERNAME = 'auditor';

    public const string READER_PASSWORD = 'auditor-password';

    public const string STRANGER_USERNAME = 'stranger';

    public const string STRANGER_PASSWORD = 'stranger-password';

    public static function isSupported(): bool
    {
        return class_exists(ApiPlatformBundle::class) && class_exists(SecurityBundle::class);
    }

    /**
     * @return array<string, string>
     */
    public static function credentialsOf(string $username, string $password): array
    {
        return ['PHP_AUTH_USER' => $username, 'PHP_AUTH_PW' => $password];
    }

    /**
     * @return array<string, string>
     */
    public static function readerCredentials(): array
    {
        return self::credentialsOf(self::READER_USERNAME, self::READER_PASSWORD);
    }

    /**
     * @return array<string, string>
     */
    public static function strangerCredentials(): array
    {
        return self::credentialsOf(self::STRANGER_USERNAME, self::STRANGER_PASSWORD);
    }

    /**
     * @return iterable<BundleInterface>
     */
    #[\Override]
    public function registerBundles(): iterable
    {
        yield from parent::registerBundles();
        yield new SecurityBundle();
        yield new ApiPlatformBundle();
    }

    #[\Override]
    protected function auditTrailConfig(): array
    {
        return array_replace_recursive(
            [
                'bridges' => [
                    'api_platform' => [
                        'enabled' => true,
                        'route_prefix' => self::ROUTE_PREFIX,
                        'security' => self::SECURITY_EXPRESSION,
                    ],
                ],
            ],
            parent::auditTrailConfig(),
        );
    }

    /**
     * Enabled explicitly rather than left to auto-detection: these components are transitive
     * dependencies here, and FrameworkBundle only turns a feature on by itself when the
     * package is a direct requirement of the root project.
     */
    #[\Override]
    protected function frameworkConfig(): array
    {
        return [
            ...parent::frameworkConfig(),
            'property_access' => ['enabled' => true],
            'property_info' => ['enabled' => true],
            'serializer' => ['enabled' => true],
            'validation' => ['enabled' => true],
        ];
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', 'api_platform');
    }

    #[\Override]
    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        parent::configureContainer($container, $loader);

        $container->loadFromExtension('api_platform', [
            'title' => 'Audit trail test API',
            'version' => '1.0.0',
            'formats' => ['jsonld' => ['application/ld+json'], 'json' => ['application/json']],
            'mapping' => ['paths' => []],
        ]);

        $container->loadFromExtension('security', [
            'password_hashers' => [InMemoryUser::class => ['algorithm' => 'plaintext']],
            'providers' => [
                'audit_trail_test_users' => [
                    'memory' => [
                        'users' => [
                            self::READER_USERNAME => [
                                'password' => self::READER_PASSWORD,
                                'roles' => ['ROLE_AUDIT_READER'],
                            ],
                            self::STRANGER_USERNAME => [
                                'password' => self::STRANGER_PASSWORD,
                                'roles' => ['ROLE_USER'],
                            ],
                        ],
                    ],
                ],
            ],
            'firewalls' => [
                'main' => [
                    'pattern' => '^/',
                    'stateless' => true,
                    'provider' => 'audit_trail_test_users',
                    'http_basic' => true,
                ],
            ],
        ]);
    }
}
