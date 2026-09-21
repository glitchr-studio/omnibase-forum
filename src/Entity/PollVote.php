<?php

namespace Base\Forum\Entity;

use App\Entity\User;
use Doctrine\ORM\Mapping as ORM;

/**
 * One member's answer to a poll. One per member and poll: the unique index is
 * what holds it, not a check in the controller, so two clicks racing each
 * other cannot cast two votes.
 */
#[ORM\Entity]
#[ORM\Table(name: 'forum_poll_vote')]
#[ORM\UniqueConstraint(name: 'forum_poll_vote_once', columns: ['poll_id', 'user_id'])]
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
