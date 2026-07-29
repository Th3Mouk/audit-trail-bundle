<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Attribute;

/**
 * Opt an entity in to the audit trail.
 *
 * Auditing is opt-in by design: it keeps write volume bounded and makes the audited
 * surface a greppable, reviewable fact (`grep -r '#[Auditable]' src/`).
 *
 * The attribute is inherited, so marking a base class audits its children. A child
 * opts back out with `#[Auditable(enabled: false)]`.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Auditable
{
    public function __construct(
        public bool $enabled = true,
    ) {
    }
}
