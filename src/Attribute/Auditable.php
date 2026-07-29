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
 *
 * `type` is the short name entries are discriminated by, in place of the class name — `membership`
 * rather than `App\Entity\OrganizationMembership`. Declaring it makes the trail survive a rename;
 * leaving it out derives it from the short class name in kebab-case, which is convenient and splits
 * the history in two the day the class is renamed.
 *
 * Unlike `enabled`, `type` is *not* inherited: a subclass gets its own type unless it declares one,
 * because two classes answering to a single type would merge two histories — a build failure here,
 * not a surprise in production. See {@see \Th3Mouk\AuditTrail\Metadata\AuditTypeResolver}.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Auditable
{
    public function __construct(
        public bool $enabled = true,
        public ?string $type = null,
    ) {
    }
}
