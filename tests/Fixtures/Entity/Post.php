<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Fixtures\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Th3Mouk\AuditTrail\Attribute\Auditable;
use Th3Mouk\AuditTrail\Attribute\AuditLabel;
use Th3Mouk\AuditTrail\Attribute\AuditMasked;
use Th3Mouk\AuditTrail\Attribute\NotAuditable;
use Th3Mouk\AuditTrail\Tests\Fixtures\Enum\PostStatus;

/**
 * The reference audited entity: one property per capture rule.
 *
 * `title` is the label, `body` and `views` are ordinary tracked scalars, `secret` and
 * `apiKey` are masked (the second with its own mask, to prove the per-property override),
 * `internalNotes` is ignored and therefore not row-triggering, `author` is an owning
 * association, and `tags`/`publishedAt`/`status` cover the array, date and backed-enum
 * value mappings.
 */
#[ORM\Entity]
#[ORM\Table(name: 'fixture_posts')]
#[Auditable]
class Post
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $body = '';

    #[ORM\Column]
    private int $views = 0;

    #[AuditMasked]
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $secret = null;

    #[AuditMasked(mask: '[redacted]')]
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $apiKey = null;

    #[NotAuditable]
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $internalNotes = null;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $tags = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(length: 16, enumType: PostStatus::class)]
    private PostStatus $status = PostStatus::Draft;

    /** @var Collection<int, Comment> */
    #[ORM\OneToMany(targetEntity: Comment::class, mappedBy: 'post', cascade: ['persist', 'remove'])]
    private Collection $comments;

    public function __construct(#[AuditLabel]
        #[ORM\Column(length: 255)]
        private string $title, #[ORM\ManyToOne(targetEntity: Author::class)]
        #[ORM\JoinColumn(nullable: true)]
        private ?Author $author = null)
    {
        $this->comments = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function rename(string $title): void
    {
        $this->title = $title;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function rewrite(string $body): void
    {
        $this->body = $body;
    }

    public function getViews(): int
    {
        return $this->views;
    }

    public function recordView(): void
    {
        ++$this->views;
    }

    public function getSecret(): ?string
    {
        return $this->secret;
    }

    public function rotateSecret(?string $secret): void
    {
        $this->secret = $secret;
    }

    public function getApiKey(): ?string
    {
        return $this->apiKey;
    }

    public function rotateApiKey(?string $apiKey): void
    {
        $this->apiKey = $apiKey;
    }

    public function getInternalNotes(): ?string
    {
        return $this->internalNotes;
    }

    public function annotate(?string $internalNotes): void
    {
        $this->internalNotes = $internalNotes;
    }

    public function getAuthor(): ?Author
    {
        return $this->author;
    }

    public function assignTo(?Author $author): void
    {
        $this->author = $author;
    }

    /**
     * @return list<string>
     */
    public function getTags(): array
    {
        return $this->tags;
    }

    public function retag(string ...$tags): void
    {
        $this->tags = array_values($tags);
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function publishOn(?\DateTimeImmutable $publishedAt): void
    {
        $this->publishedAt = $publishedAt;
    }

    public function getStatus(): PostStatus
    {
        return $this->status;
    }

    public function moveTo(PostStatus $status): void
    {
        $this->status = $status;
    }

    /**
     * @return Collection<int, Comment>
     */
    public function getComments(): Collection
    {
        return $this->comments;
    }

    public function addComment(Comment $comment): void
    {
        if (!$this->comments->contains($comment)) {
            $this->comments->add($comment);
        }
    }
}
