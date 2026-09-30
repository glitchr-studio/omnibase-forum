<?php

namespace Base\Forum\Entity;

use App\Entity\User;
use Doctrine\ORM\Mapping as ORM;

/**
 * One answer a member picked in a poll: one row per answer, so a poll that
 * allows several (Poll::$maxChoices) holds a member's picks as several rows.
 * The same answer twice is what the unique index refuses - not a check in the
 * controller - so two clicks racing each other cannot count one pick twice;
 * how many answers a member may pick is the vote action's to hold.
 */
#[ORM\Entity]
#[ORM\Table(name: 'forum_poll_vote')]
#[ORM\UniqueConstraint(name: 'forum_poll_vote_once', columns: ['poll_id', 'user_id', 'choice'])]
class PollVote
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    protected $id;

    #[ORM\ManyToOne(targetEntity: Poll::class, inversedBy: 'votes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected ?Poll $poll = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected ?User $user = null;

    /** The index of the answer chosen, in Poll::$options. */
    #[ORM\Column(type: 'smallint')]
    protected int $choice = 0;

    #[ORM\Column(type: 'datetime')]
    protected \DateTimeInterface $createdAt;

    public function __construct(Poll $poll, User $user, int $choice)
    {
        $this->poll = $poll;
        $this->user = $user;
        $this->choice = $choice;
        $this->createdAt = new \DateTime();
        $poll->addVote($this);
    }

    public function getId(): ?int { return $this->id; }
    public function getPoll(): ?Poll { return $this->poll; }
    public function getUser(): ?User { return $this->user; }
    public function getChoice(): int { return $this->choice; }
    public function getCreatedAt(): \DateTimeInterface { return $this->createdAt; }
}
