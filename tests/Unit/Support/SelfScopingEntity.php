<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Unit\Support;

use Th3Mouk\AuditTrail\Attribute\Auditable;
use Th3Mouk\AuditTrail\Attribute\AuditScope;
use Th3Mouk\AuditTrail\Model\AuditRef;
use Th3Mouk\AuditTrail\Scope\AuditScopeProviderInterface;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\Author;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\Post;

/**
 * Says its root twice, and contradicts itself on purpose.
 *
 * The attribute names an Author reached through an `author` hop this class does not even
 * expose; the interface names a Post. Only one answer can come out, and which one it is
 * settles the precedence question — the attribute path would raise instead.
 */
#[Auditable]
#[AuditScope(root: Author::class, via: 'author')]
final readonly class SelfScopingEntity implements AuditScopeProviderInterface
{
    public function __construct(
        private ?AuditRef $root = null,
    ) {
    }

    public static function rootedAtPost(string $id, ?string $label = null): self
    {
        return new self(AuditRef::of(Post::class, $id, $label));
    }

    public static function rootless(): self
    {
        return new self();
    }

    public function resolveAuditRoot(): ?AuditRef
    {
        return $this->root;
    }
}
