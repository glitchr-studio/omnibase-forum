<?php

namespace Base\Forum\Security;

use Base\Forum\Entity\Category;
use Base\Forum\Entity\Post;
use Base\Forum\Entity\Topic;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Who may do what on the forum.
 *
 *   FORUM_READ      a category (its required role, if any)
 *   FORUM_POST      a category: signed in, board not locked, readable
 *   FORUM_REPLY     a topic: signed in, topic and board not locked
 *   FORUM_EDIT      a topic or post: its author, or a moderator
 *   FORUM_MODERATE  anything: the configured moderator role
 */
final class ForumVoter extends Voter
{
    public const READ = 'FORUM_READ';
    public const POST = 'FORUM_POST';
    public const REPLY = 'FORUM_REPLY';
    public const EDIT = 'FORUM_EDIT';
    public const MODERATE = 'FORUM_MODERATE';

    public function __construct(
        private readonly Security $security,
        #[Autowire('%forum.moderator_role%')] private readonly string $moderatorRole = 'ROLE_ADMIN',
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::READ, self::POST, self::REPLY, self::EDIT, self::MODERATE], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        $isUser = $user instanceof UserInterface;
        $isModerator = $this->security->isGranted($this->moderatorRole);

        if (self::MODERATE === $attribute) {
            return $isModerator;
        }

        $category = match (true) {
            $subject instanceof Category => $subject,
            $subject instanceof Topic => $subject->getCategory(),
            $subject instanceof Post => $subject->getTopic()?->getCategory(),
            default => null,
        };

        $canRead = null === $category?->getRequiredRole() || $this->security->isGranted($category->getRequiredRole());

        switch ($attribute) {
            case self::READ:
                return $canRead;

            case self::POST:
                return $isUser && $canRead && $category instanceof Category && $category->isBoard()
                    && (!$category->isLocked() || $isModerator);

            case self::REPLY:
                if (!$subject instanceof Topic || !$isUser || !$canRead) {
                    return false;
                }
                return $isModerator || (!$subject->isLocked() && !$subject->getCategory()?->isLocked());

            case self::EDIT:
                if (!$isUser || !$canRead) {
                    return false;
                }
                if ($isModerator) {
                    return true;
                }
                $author = $subject instanceof Post ? $subject->getAuthor() : ($subject instanceof Topic ? $subject->getAuthor() : null);
                $locked = $subject instanceof Post ? ($subject->getTopic()?->isLocked() ?? false) : ($subject instanceof Topic && $subject->isLocked());

                return null !== $author && $author === $user && !$locked;
        }

        return false;
    }
}
