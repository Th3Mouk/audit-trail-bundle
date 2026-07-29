<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Attribute;

/**
 * Anchor an entity's entries to the aggregate they belong to.
 *
 * This is what makes a per-aggregate history panel a single indexed query: an answer
 * edited deep inside a questionnaire records the questionnaire as its root, so
 * "show me everything that happened to this questionnaire" needs no joins.
 *
 * `via` is a dotted path of getters walked from the audited entity, e.g. `'pillar.strategy'`
 * resolves `getPillar()->getStrategy()`. The walk is identifier-only and never triggers a
 * query; a null hop simply yields no root.
 *
 * `type` is the short, refactor-safe discriminator stored on the entry (and used as the
 * `rootType` filter value). It defaults to the kebab-cased short name of `root`.
 *
 * For roots a getter chain cannot express, implement
 * {@see \Th3Mouk\AuditTrail\Scope\AuditScopeProviderInterface} on the entity instead.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AuditScope
{
    /**
     * @param class-string $root
     */
    public function __construct(
        public string $root,
        public string $via,
        public ?string $type = null,
    ) {
    }
}
