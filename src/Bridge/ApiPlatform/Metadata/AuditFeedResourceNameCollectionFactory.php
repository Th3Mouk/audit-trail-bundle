<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Bridge\ApiPlatform\Metadata;

use ApiPlatform\Metadata\Resource\Factory\ResourceNameCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\ResourceNameCollection;
use Th3Mouk\AuditTrail\Entity\AuditLog;

/**
 * Declares the audit log as an API Platform resource without an attribute on the entity.
 *
 * The entity stays a plain Doctrine entity so the bundle boots with api-platform absent; the
 * class only enters the resource name collection while this decorator is registered.
 */
final readonly class AuditFeedResourceNameCollectionFactory implements ResourceNameCollectionFactoryInterface
{
    public function __construct(private ResourceNameCollectionFactoryInterface $decorated)
    {
    }

    public function create(): ResourceNameCollection
    {
        $resourceClasses = [AuditLog::class];

        foreach ($this->decorated->create() as $resourceClass) {
            if (AuditLog::class !== $resourceClass) {
                $resourceClasses[] = $resourceClass;
            }
        }

        return new ResourceNameCollection($resourceClasses);
    }
}
