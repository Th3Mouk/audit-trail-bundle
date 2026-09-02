<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Th3Mouk\AuditTrail\Actor\SecurityTokenActorResolver;

/**
 * Registers the default security actor resolver — and only when the application genuinely has
 * one to read from.
 *
 * `AuditTrailExtension::load()` cannot make this decision itself. Symfony compiles every
 * extension's configuration through `MergeExtensionConfigurationPass`, which calls each
 * extension's `load()` against an isolated, throwaway `ContainerBuilder` that never has any other
 * bundle's extension registered on it — `ContainerBuilder::hasExtension('security')`, asked from
 * inside `load()`, is unconditionally `false`, whichever bundle asks and in whatever order
 * bundles were registered. This is not a bundle-ordering edge case: it fails in every
 * application, always, which is why `SecurityTokenActorResolver` never entered the container even
 * with `symfony/security-bundle` fully configured.
 *
 * A compiler pass runs later, against the real container, after every extension's configuration
 * has already been merged into it — the only point from which this bundle can honestly ask what
 * the application actually registered. `RegisterAuditFeedPass` answers the equivalent question
 * for the API Platform bridge the same way.
 *
 * The single-argument `$container->register(SecurityTokenActorResolver::class)` this replaces
 * left the definition's class attribute unset — `ContainerBuilder::register()`'s second
 * parameter, not its id, decides the class — which `CheckDefinitionValidityPass` rejects outright.
 * That line could never have run without failing every build it reached, which the
 * `hasExtension()` bug above hid: fixing only the guard, without this, would have turned a
 * silently absent actor into a container that refuses to compile at all.
 */
final class RegisterSecurityActorResolverPass implements CompilerPassInterface
{
    public static function register(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new self());
    }

    public function process(ContainerBuilder $container): void
    {
        if (!interface_exists(TokenStorageInterface::class) || !$container->has('security.token_storage')) {
            return;
        }

        $container->register(SecurityTokenActorResolver::class, SecurityTokenActorResolver::class)
            ->setArguments([new Reference('security.token_storage')])
            ->addTag('audit_trail.actor_resolver', ['priority' => -100]);
    }
}
