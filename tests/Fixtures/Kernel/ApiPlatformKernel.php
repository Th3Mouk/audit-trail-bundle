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
use Th3Mouk\AuditTrail\Tests\Fixtures\Security\SubjectPermissionVoter;

/**
 * The Doctrine-only setup plus API Platform, over HTTP, behind a security expression.
 *
 * The expression is supplied by this fixture application, never by the bundle: the bundle
 * has no idea what roles exist here. Two users make the point provable — one that the
 * expression admits, one it does not.
 */
final class ApiPlatformKernel extends TestKernel
{
    public const string READER_ATTRIBUTE = 'ROLE_AUDIT_READER';
    public const string SECURITY_EXPRESSION = "is_granted('ROLE_AUDIT_READER')";

    public const string ROUTE_PREFIX = '/audit-logs';

    public const string READER_USERNAME = 'auditor';

    public const string READER_PASSWORD = 'auditor-password';

    public const string STRANGER_USERNAME = 'stranger';

    public const string STRANGER_PASSWORD = 'stranger-password';

    public const string SUBJECT_ATTRIBUTE = SubjectPermissionVoter::ATTRIBUTE;

    public const string SUBJECT = SubjectPermissionVoter::SUBJECT;

    public const string SUBJECT_READER_USERNAME = 'subject-auditor';

    public const string SUBJECT_READER_PASSWORD = 'subject-auditor-password';

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
     * @return array<string, string>
     */
    public static function subjectReaderCredentials(): array
    {
        return self::credentialsOf(self::SUBJECT_READER_USERNAME, self::SUBJECT_READER_PASSWORD);
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

    /**
     * The `access` node is replaced wholesale rather than merged.
     *
     * Its three modes are mutually exclusive, and `array_replace_recursive` cannot express "this one
     * no longer applies": replacing a populated array with an empty one leaves the original in place.
     * A test choosing the expression or public mode would therefore silently declare two modes and
     * hit the configuration's own guard. Everything else still merges, so a test can tune the page
     * size without restating the access rule.
     */
    #[\Override]
    protected function auditTrailConfig(): array
    {
        $overrides = parent::auditTrailConfig();

        $config = array_replace_recursive(
            [
                'bridges' => [
                    'api_platform' => [
                        'enabled' => true,
                        'route_prefix' => self::ROUTE_PREFIX,
                        // The grants form, because it is the one most applications will use: an
                        // attribute the host's voters understand, compiled into the expression for
                        // you. The raw-expression and public modes get their own tests.
                        'access' => ['grants' => [self::READER_ATTRIBUTE]],
                    ],
                ],
            ],
            $overrides,
        );

        $declaredAccess = $overrides['bridges']['api_platform']['access'] ?? null;

        if (\is_array($declaredAccess)) {
            $config['bridges']['api_platform']['access'] = $declaredAccess;
        }

        return $config;
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
                            self::SUBJECT_READER_USERNAME => [
                                'password' => self::SUBJECT_READER_PASSWORD,
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

        // A permission voter of the shape real applications write — attribute plus subject — so the
        // feed's `grants` configuration can be proven to reach is_granted($attribute, $subject).
        $container
            ->register(SubjectPermissionVoter::class, SubjectPermissionVoter::class)
            ->setArguments([[self::SUBJECT_READER_USERNAME]])
            ->addTag('security.voter');
    }
}
