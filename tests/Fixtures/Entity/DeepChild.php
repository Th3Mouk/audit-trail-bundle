<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Fixtures\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Th3Mouk\AuditTrail\Attribute\Auditable;
use Th3Mouk\AuditTrail\Attribute\AuditScope;

/**
 * The nested walk: two hops from the audited entity to the aggregate root.
 */
#[ORM\Entity]
#[ORM\Table(name: 'fixture_deep_children')]
#[Auditable]
#[AuditScope(root: Post::class, via: 'comment.post')]
class DeepChild
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(#[ORM\ManyToOne(targetEntity: Comment::class, inversedBy: 'children')]
        #[ORM\JoinColumn(nullable: false)]
        private Comment $comment, #[ORM\Column(type: Types::TEXT)]
        private string $note)
    {
        $this->comment->addChild($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getComment(): Comment
    {
        return $this->comment;
    }

    public function getNote(): string
    {
        return $this->note;
    }

    public function annotate(string $note): void
    {
        $this->note = $note;
    }
}
