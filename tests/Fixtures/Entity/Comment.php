<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Fixtures\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Th3Mouk\AuditTrail\Attribute\Auditable;
use Th3Mouk\AuditTrail\Attribute\AuditLabel;
use Th3Mouk\AuditTrail\Attribute\AuditScope;

/**
 * A child anchored to its aggregate root in one hop, labelled by a method.
 */
#[ORM\Entity]
#[ORM\Table(name: 'fixture_comments')]
#[Auditable]
#[AuditScope(root: Post::class, via: 'post')]
class Comment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** @var Collection<int, DeepChild> */
    #[ORM\OneToMany(targetEntity: DeepChild::class, mappedBy: 'comment', cascade: ['persist', 'remove'])]
    private Collection $children;

    public function __construct(#[ORM\ManyToOne(targetEntity: Post::class, inversedBy: 'comments')]
        #[ORM\JoinColumn(nullable: false)]
        private Post $post, #[ORM\Column(type: Types::TEXT)]
        private string $message)
    {
        $this->children = new ArrayCollection();
        $this->post->addComment($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPost(): Post
    {
        return $this->post;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function edit(string $message): void
    {
        $this->message = $message;
    }

    #[AuditLabel]
    public function excerpt(): string
    {
        return mb_substr($this->message, 0, 20);
    }

    /**
     * @return Collection<int, DeepChild>
     */
    public function getChildren(): Collection
    {
        return $this->children;
    }

    public function addChild(DeepChild $child): void
    {
        if (!$this->children->contains($child)) {
            $this->children->add($child);
        }
    }
}
