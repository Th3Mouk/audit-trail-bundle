<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Attribute;

/**
 * Designate the human-readable title of an entity.
 *
 * The resolved value is snapshotted onto every entry that references the entity, so
 * the trail stays readable after a rename or a delete. May target a property or a
 * getter. When absent, the resolver falls back to `__toString()` and then the identifier.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
final readonly class AuditLabel
{
}
