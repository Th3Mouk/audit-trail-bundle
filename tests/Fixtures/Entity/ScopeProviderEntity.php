<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;
use Th3Mouk\AuditTrail\Attribute\Auditable;
use Th3Mouk\AuditTrail\Model\AuditRef;
use Th3Mouk\AuditTrail\Scope\AuditScopeProviderInterface;

/**
 * The escape hatch: the entity decides its own root, and wins over #[AuditScope].
 *
 * The default derivation deliberately reads only the identifier of an associated Post and
 * never its title — reading the title would initialise a proxy, which is forbidden inside
 * a flush. Tests that want a labelled root inject one with `provideRoot()`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'fixture_scope_providers')]
#[Auditable]
class ScopeProviderEntity implements AuditScopeProviderInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $note = '';

    private ?AuditRef $providedRoot = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Post::class)]
        #[ORM\JoinColumn(nullable: true)]
        private ?Post $post = null,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNote(): string
    {
        return $this->note;
    }

    public function annotate(string $note): void
    {
        $this->note = $note;
    }

    public function getPost(): ?Post
    {
        return $this->post;
    }

    public function provideRoot(?AuditRef $root): void
    {
        $this->providedRoot = $root;
    }

    public function resolveAuditRoot(): ?AuditRef
    {
        if (null !== $this->providedRoot) {
            return $this->providedRoot;
        }

        $postId = $this->post?->getId();

        return null === $postId ? null : AuditRef::of(Post::class, $postId);
    }
}
