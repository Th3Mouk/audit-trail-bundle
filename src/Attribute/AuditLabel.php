<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Attribute;

/**
 * Designate the human-readable title of an entity.
 *
 * The resolved value is snapshotted onto every entry that references the entity, so
 * the trail stays readable after a rename or a delete. May target a property or a
 * getter. When absent, the resolver falls back to `__toString()` and then the identifier.
 *
 * **A method form must be memory-only.** It is invoked inside Doctrine's flush, so reading an
 * association that is not already loaded issues a query at the one moment the pipeline is built to
 * avoid — and on a to-one association that is a query per audited entity. Prefer a property, or a
 * method that concatenates its own columns. A method that throws costs the label, not the flush,
 * but a method that queries costs everyone.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
final readonly class AuditLabel
{
}
