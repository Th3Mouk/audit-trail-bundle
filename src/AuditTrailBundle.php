<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Th3Mouk\AuditTrail\Bridge\ApiPlatform\DependencyInjection\RegisterAuditFeedPass;
use Th3Mouk\AuditTrail\DependencyInjection\AuditTrailExtension;
use Th3Mouk\AuditTrail\DependencyInjection\RegisterSecurityActorResolverPass;

/**
 * Register in `config/bundles.php`, then configure under the `audit_trail` key.
 *
 * Capture gates and value serializers need no compiler pass of their own: they are built from
 * tagged iterators, which Symfony already collects and orders by the `priority` tag attribute.
 * Actor resolvers mostly work the same way, except for the bundle's own conditional default —
 * whether `symfony/security-bundle` is genuinely configured cannot be answered from inside
 * `AuditTrailExtension::load()`, for the same reason the API Platform bridge cannot decide
 * there either. Both passes registered here decide for themselves whether they apply.
 */
final class AuditTrailBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        RegisterAuditFeedPass::register($container);
        RegisterSecurityActorResolverPass::register($container);
    }

    #[\Override]
    public function getContainerExtension(): AuditTrailExtension
    {
        if (!$this->extension instanceof AuditTrailExtension) {
            $this->extension = new AuditTrailExtension();
        }

        return $this->extension;
    }
}
