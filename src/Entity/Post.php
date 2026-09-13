<?php

namespace Base\Forum\Entity;

use App\Entity\User;
use Base\Database\Attribute\Timestamp;
use Base\Forum\Repository\PostRepository;
use Base\Traits\BaseTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One message in a topic. Written in Markdown (a "modern forum" writes
 * Markdown, the way Discourse and the CERN ROOT forum do) and rendered
 * through Base\Forum\Service\MarkdownRenderer, which escapes raw HTML.
 *
 * Deleted posts are kept (deletedAt) so a topic never loses its numbering
 * and moderators can see what was removed.
 */
#[ORM\Entity(repositoryClass: PostRepository::class)]
#[ORM\Table(name: 'forum_post')]
#[ORM\Index(columns: ['createdAt'], name: 'forum_post_created_idx')]
class Post
{
    use BaseTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    protected $id;

    #[ORM\ManyToOne(targetEntity: Topic::class, inversedBy: 'posts')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected ?Topic $topic = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    protected ?User $author = null;

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank(groups: ['new', 'edit'])]
    protected ?string $content = null;

    #[ORM\Column(type: 'datetime')]
    #[Timestamp(on: 'create')]
    protected ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: 'datetime')]
    #[Timestamp(on: ['create', 'update'])]
    protected ?\DateTimeInterface $updatedAt = null;

    /** Set on an edit by the author, so the post shows "modifié" - not on moderation. */
    #[ORM\Column(type: 'datetime', nullable: true)]
    protected ?\DateTimeInterface $editedAt = null;

    #[ORM\Column(type: 'integer')]
    protected int $editCount = 0;

    #[ORM\Column(type: 'datetime', nullable: true)]
    protected ?\DateTimeInterface $deletedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    protected ?User $deletedBy = null;

    /** Where the reader came from: the post being answered, for the "en réponse à" link. */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    protected ?Post $replyTo = null;

    public function __construct(?User $author = null, ?string $content = null)
    {
        $this->author = $author;
        $this->content = $content;
    }

    public function __toString(): string
    {
        return mb_substr((string) $this->content, 0, 60);
    }

    public function getId(): ?int { return $this->id; }

    public function getTopic(): ?Topic { return $this->topic; }
    public function setTopic(?Topic $topic): self { $this->topic = $topic; return $this; }

    public function getAuthor(): ?User { return $this->author; }
    public function setAuthor(?User $author): self { $this->author = $author; return $this; }

    public function getContent(): ?string { return $this->content; }
    public function setContent(?string $content): self { $this->content = $content; return $this; }

    public function getCreatedAt(): ?\DateTimeInterface { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeInterface { return $this->updatedAt; }

    public function getEditedAt(): ?\DateTimeInterface { return $this->editedAt; }
    public function markEdited(): self
    {
        $this->editedAt = new \DateTime();
        ++$this->editCount;
        return $this;
    }
    public function getEditCount(): int { return $this->editCount; }

    public function isDeleted(): bool { return null !== $this->deletedAt; }
    public function getDeletedAt(): ?\DateTimeInterface { return $this->deletedAt; }
    public function getDeletedBy(): ?User { return $this->deletedBy; }
    public function delete(?User $by = null): self
    {
        $this->deletedAt = new \DateTime();
        $this->deletedBy = $by;
        return $this;
    }
    public function restore(): self
    {
        $this->deletedAt = null;
        $this->deletedBy = null;
        return $this;
    }

    public function getReplyTo(): ?Post { return $this->replyTo; }
    public function setReplyTo(?Post $replyTo): self { $this->replyTo = $replyTo; return $this; }

    /** Is this the topic's opening post? */
    public function isFirst(): bool
    {
        return $this->topic && $this->topic->getFirstPost() === $this;
    }

    /** 1-based position in the topic, for "#12" anchors and permalinks. */
    public function getNumber(): int
    {
        if (!$this->topic) {
            return 1;
        }

        $n = 0;
        foreach ($this->topic->getPosts() as $post) {
            ++$n;
            if ($post === $this) {
                return $n;
            }
        }

        return $n + 1;
    }
}
