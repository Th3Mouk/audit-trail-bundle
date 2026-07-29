<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Capture;

/**
 * Produces the human-readable title snapshotted onto an audit entry.
 *
 * The default implementation reads #[AuditLabel], then `__toString()`, then falls back
 * to the identifier. Decorate this service to centralise labelling rules across a
 * domain (translations, formatted names, tenant prefixes...).
 */
interface LabelResolverInterface
{
    public function resolve(object $entity): ?string;
}
