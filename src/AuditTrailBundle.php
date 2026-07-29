<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Th3Mouk\AuditTrail\Bridge\ApiPlatform\DependencyInjection\RegisterAuditFeedPass;
use Th3Mouk\AuditTrail\DependencyInjection\AuditTrailExtension;

/**
 * Register in `config/bundles.php`, then configure under the `audit_trail` key.
 *
 * The three extension chains (actor resolvers, capture gates, value serializers) need no
 * compiler pass: they are built from tagged iterators, which Symfony already collects and
 * orders by the `priority` tag attribute. The one pass registered here builds the API
 * Platform feed, and it decides for itself whether the bridge applies.
 */
final class AuditTrailBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        RegisterAuditFeedPass::register($container);
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
