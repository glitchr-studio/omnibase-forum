<?php

namespace Base\Forum\Entity;

use App\Entity\User;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A topic's poll - phpBB's "sondage", which the 2004 BBS had above a topic's
 * messages: one question, a few answers, one vote per member.
 *
 * The answers are a plain list kept on the poll (their order is their
 * identity: a vote records the index it chose), because a poll's answers are
 * written once, with the topic, and never edited - editing an answer after
 * votes were cast would change what those votes meant.
 */
#[ORM\Entity]
#[ORM\Table(name: 'forum_poll')]
class Poll
{
    public const MIN_OPTIONS = 2;
    public const MAX_OPTIONS = 10;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    protected $id;

    #[ORM\OneToOne(targetEntity: Topic::class, inversedBy: 'poll')]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    protected ?Topic $topic = null;

    #[ORM\Column(type: 'string', length: 200)]
    protected string $question = '';

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    protected array $options = [];

    #[ORM\Column(type: 'datetime')]
    protected \DateTimeInterface $createdAt;

    /** @var Collection<int, PollVote> */
    #[ORM\OneToMany(targetEntity: PollVote::class, mappedBy: 'poll', cascade: ['persist', 'remove'], orphanRemoval: true)]
    protected Collection $votes;

    /** @param list<string> $options */
    public function __construct(Topic $topic, string $question, array $options)
    {
        $this->topic = $topic;
        $this->question = $question;
        $this->options = array_values($options);
        $this->createdAt = new \DateTime();
        $this->votes = new ArrayCollection();
        $topic->setPoll($this);
    }

    public function getId(): ?int { return $this->id; }
    public function getTopic(): ?Topic { return $this->topic; }
    public function getQuestion(): string { return $this->question; }
    /** @return list<string> */
    public function getOptions(): array { return $this->options; }
    public function getCreatedAt(): \DateTimeInterface { return $this->createdAt; }
    /** @return Collection<int, PollVote> */
    public function getVotes(): Collection { return $this->votes; }

    public function addVote(PollVote $vote): self
    {
        if (!$this->votes->contains($vote)) {
            $this->votes[] = $vote;
        }

        return $this;
    }

    /** A poll closes with its topic: a locked topic takes no reply, and no vote either. */
    public function isOpen(): bool
    {
        return !$this->topic?->isLocked();
    }

    /** @return array<int, int> votes per answer, by the answer's index */
    public function getCounts(): array
    {
        $counts = array_fill(0, count($this->options), 0);
        foreach ($this->votes as $vote) {
            if (isset($counts[$vote->getChoice()])) {
                ++$counts[$vote->getChoice()];
            }
        }

        return $counts;
    }

    public function getTotal(): int
    {
        return array_sum($this->getCounts());
    }

    /** The answer a member chose, or null when they have not voted. */
    public function getChoiceOf(?User $user): ?int
    {
        if (!$user) {
            return null;
        }
        foreach ($this->votes as $vote) {
            if ($vote->getUser() === $user) {
                return $vote->getChoice();
            }
        }

        return null;
    }

    public function hasOption(int $choice): bool
    {
        return $choice >= 0 && $choice < count($this->options);
    }
}
