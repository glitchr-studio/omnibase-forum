<?php

namespace Base\Forum\Entity;

use App\Entity\User;
use Base\Database\Attribute\DiscriminatorEntry;
use Base\Entity\Thread;
use Base\Enum\ThreadState;
use Base\Forum\Repository\TopicRepository;
use Base\Service\Model\LinkableInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * A forum topic is a Thread: title, slug, tags, owner(s), followers, likes,
 * mentions, publish state and soft delete come from base-bundle. The
 * body of the topic is its first Post, so a topic and its replies are read,
 * quoted, edited and moderated the same way.
 *
 * A topic may be written ahead of its hour (schedule()): until publishedAt it
 * is out of every list and closed to replies, and only its authors and the
 * moderators of its board can open it (ForumVoter). The date alone decides -
 * nothing has to run at that hour for it to appear.
 */
#[ORM\Entity(repositoryClass: TopicRepository::class)]
#[ORM\Table(name: 'forum_topic')]
#[DiscriminatorEntry(value: 'forum_topic')]
class Topic extends Thread implements LinkableInterface
{
    #[ORM\ManyToOne(targetEntity: Category::class, inversedBy: 'topics')]
    #[ORM\JoinColumn(nullable: false)]
    protected ?Category $category = null;

    #[ORM\OneToMany(targetEntity: Post::class, mappedBy: 'topic', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => \SortDirection::Ascending, 'id' => \SortDirection::Ascending])]
    protected Collection $posts;

    /** Pinned topics stay on top of their board (phpBB's "sticky"). */
    #[ORM\Column(type: 'boolean')]
    protected bool $pinned = false;

    /** A locked topic is readable but takes no reply. */
    #[ORM\Column(type: 'boolean')]
    protected bool $locked = false;

    #[ORM\Column(type: 'integer')]
    protected int $views = 0;

    /** Replies, i.e. posts minus the opening one - denormalised for the lists. */
    #[ORM\Column(type: 'integer')]
    protected int $replies = 0;

    #[ORM\Column(type: 'datetime', nullable: true)]
    protected ?\DateTimeInterface $lastPostAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    protected ?User $lastPoster = null;

    /** The topic's poll, when it was opened with one (see Poll). */
    #[ORM\OneToOne(targetEntity: Poll::class, mappedBy: 'topic', cascade: ['persist', 'remove'])]
    protected ?Poll $poll = null;

    public function __construct(?User $owner = null, ?Category $category = null, ?string $title = null)
    {
        parent::__construct($owner, null, $title);
        $this->posts = new ArrayCollection();
        $this->category = $category;

        // Public the moment it is written, unless it is given an hour to come
        // out at (schedule()).
        $this->setState(ThreadState::PUBLISH);
        $this->setPublishedAt(new \DateTime());
    }

    /**
     * Out at $at when that is still to come; otherwise now.
     *
     * The state stays PUBLISH either way. Base-bundle's FUTURE waits for its
     * thread:publishable command to be run to become PUBLISH, and nothing runs
     * it here: the lists go by the date (TopicRepository::whereOut()), so a
     * scheduled topic comes out at its hour on its own.
     *
     * A topic nobody has answered yet moves its last activity to that hour
     * too: the lists sort by it, and a topic out at 18:00 must come in at
     * 18:00, not at the hour it was written.
     */
    public function schedule(?\DateTimeInterface $at): self
    {
        $now = new \DateTime();
        $this->setPublishedAt($at && $at > $now ? \DateTime::createFromInterface($at) : $now);
        $this->setState(ThreadState::PUBLISH);
        if (0 === $this->replies) {
            $this->lastPostAt = $this->getPublishedAt();
        }

        return $this;
    }

    /** Written, but not out yet (schedule()). */
    public function isUpcoming(): bool
    {
        $at = $this->getPublishedAt();

        return null !== $at && $at > new \DateTime();
    }

    /**
     * Who the topic is signed by: its opening post and its owner, together.
     * An admin may publish in someone else's name (TopicController).
     */
    public function setAuthor(User $author): self
    {
        foreach ($this->getOwners()->toArray() as $owner) {
            $this->removeOwner($owner);
        }
        $this->addOwner($author);
        $this->getFirstPost()?->setAuthor($author);
        if (0 === $this->replies) {
            $this->lastPoster = $author;
        }

        return $this;
    }

    public function __toString(): string
    {
        return $this->getTitle() ?? '';
    }

    public function __toLink(array $routeParameters = [], int $referenceType = UrlGeneratorInterface::ABSOLUTE_PATH): ?string
    {
        return $this->getRouter()->generate('forum_topic', array_merge($routeParameters, ['slug' => $this->getSlug()]), $referenceType);
    }

    public function getCategory(): ?Category { return $this->category; }
    public function setCategory(?Category $category): self { $this->category = $category; return $this; }

    /** @return Collection<int, Post> */
    public function getPosts(): Collection { return $this->posts; }

    public function addPost(Post $post): self
    {
        if (!$this->posts->contains($post)) {
            $this->posts[] = $post;
            $post->setTopic($this);
        }

        $this->replies = max(0, $this->posts->count() - 1);
        $this->lastPostAt = $post->getCreatedAt() ?? new \DateTime();
        $this->lastPoster = $post->getAuthor();

        return $this;
    }

    public function removePost(Post $post): self
    {
        if ($this->posts->removeElement($post)) {
            $this->replies = max(0, $this->posts->count() - 1);
            $last = $this->posts->last();
            $this->lastPostAt = $last ? $last->getCreatedAt() : null;
            $this->lastPoster = $last ? $last->getAuthor() : null;
        }

        return $this;
    }

    /** The opening post: the topic's own body. */
    public function getFirstPost(): ?Post
    {
        $first = $this->posts->first();
        return $first ?: null;
    }

    public function getAuthor(): ?User
    {
        return $this->getOwner();
    }

    public function isPinned(): bool { return $this->pinned; }
    public function setPinned(bool $pinned): self { $this->pinned = $pinned; return $this; }

    public function isLocked(): bool { return $this->locked; }
    public function setLocked(bool $locked): self { $this->locked = $locked; return $this; }

    public function getViews(): int { return $this->views; }
    public function setViews(int $views): self { $this->views = $views; return $this; }

    public function getReplies(): int { return $this->replies; }
    public function setReplies(int $replies): self { $this->replies = $replies; return $this; }

    public function getLastPostAt(): ?\DateTimeInterface { return $this->lastPostAt; }
    public function setLastPostAt(?\DateTimeInterface $lastPostAt): self { $this->lastPostAt = $lastPostAt; return $this; }

    public function getPoll(): ?Poll { return $this->poll; }
    public function setPoll(?Poll $poll): self { $this->poll = $poll; return $this; }

    public function getLastPoster(): ?User { return $this->lastPoster; }
    public function setLastPoster(?User $lastPoster): self { $this->lastPoster = $lastPoster; return $this; }

    /** Last activity, for sorting: the last reply, else the topic itself. */
    public function getActivityAt(): ?\DateTimeInterface
    {
        return $this->lastPostAt ?? $this->getCreatedAt();
    }
}
