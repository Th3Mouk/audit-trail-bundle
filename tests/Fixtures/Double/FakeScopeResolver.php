<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Fixtures\Double;

use Th3Mouk\AuditTrail\Capture\ScopeResolverInterface;
use Th3Mouk\AuditTrail\Model\AuditRef;

/**
 * Hands a capture test the aggregate root it wants to see on the entry.
 */
final readonly class FakeScopeResolver implements ScopeResolverInterface
{
    private function __construct(
        private ?AuditRef $root,
        private ?string $type,
    ) {
    }

    public static function rootedAt(AuditRef $root, ?string $type = null): self
    {
        return new self($root, $type ?? 'fixture_root');
    }

    public static function withoutRoot(): self
    {
        return new self(null, null);
    }

    public function resolve(object $entity): ?AuditRef
    {
        return $this->root;
    }

    public function resolveType(object $entity): ?string
    {
        return $this->type;
    }
}
