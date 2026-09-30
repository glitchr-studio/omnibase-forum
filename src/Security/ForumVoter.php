<?php

namespace Base\Forum\Security;

use Base\Forum\Entity\Category;
use Base\Forum\Entity\Post;
use Base\Forum\Entity\Topic;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Who may do what on the forum.
 *
 *   FORUM_READ      a category (its required role, if any)
 *   FORUM_POST      a category: signed in, board not locked, readable; an
 *                   announcement board takes topics from the admin role only
 *   FORUM_REPLY     a topic: signed in, topic and board not locked
 *   FORUM_EDIT      a topic or post: its author, or its board's moderator
 *   FORUM_MODERATE  a category, topic or post: whoever moderates its board
 *                   (see moderates()); with no subject, whoever moderates
 *                   any board at all
 *   FORUM_SCHEDULE  a category: whoever may post there and moderates it -
 *                   writing a topic to come out at a later hour
 *                   (Topic::schedule()); with no subject, whoever moderates
 *                   any board, to know whether to offer it at all
 *
 * A scheduled topic that is not out yet (Topic::isUpcoming()) is its authors'
 * and its board's moderators' alone: everyone else is refused everything on
 * it and on its posts, READ included, and nobody replies to it before its
 * hour.
 *   FORUM_ADMIN     opening a board or a group from the site: the configured
 *                   admin role (forum.admin_role)
 */
final class ForumVoter extends Voter
{
    public const READ = 'FORUM_READ';
    public const POST = 'FORUM_POST';
    public const REPLY = 'FORUM_REPLY';
    public const EDIT = 'FORUM_EDIT';
    public const MODERATE = 'FORUM_MODERATE';
    public const ADMIN = 'FORUM_ADMIN';
    public const SCHEDULE = 'FORUM_SCHEDULE';

    public function __construct(
        private readonly Security $security,
        private readonly RoleHierarchyInterface $roleHierarchy,
        #[Autowire('%forum.moderator_role%')] private readonly string $moderatorRole = 'ROLE_ADMIN',
        #[Autowire('%forum.admin_role%')] private readonly string $adminRole = 'ROLE_SUPER_ADMIN',
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::READ, self::POST, self::REPLY, self::EDIT, self::MODERATE, self::ADMIN, self::SCHEDULE], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        $isUser = $user instanceof UserInterface;

        if (self::ADMIN === $attribute) {
            return $this->security->isGranted($this->adminRole);
        }

        $category = match (true) {
            $subject instanceof Category => $subject,
            $subject instanceof Topic => $subject->getCategory(),
            $subject instanceof Post => $subject->getTopic()?->getCategory(),
            default => null,
        };
        $isModerator = $isUser && $this->moderates($token->getRoleNames(), $category);

        if (self::MODERATE === $attribute) {
            return $isModerator;
        }
        if (self::SCHEDULE === $attribute) {
            return $isModerator && (null === $category || $this->voteOnAttribute(self::POST, $category, $token));
        }

        // Not out yet: its authors' and its moderators' only, and no replies before its hour.
        $topic = $subject instanceof Topic ? $subject : ($subject instanceof Post ? $subject->getTopic() : null);
        if ($topic?->isUpcoming()) {
            if (self::REPLY === $attribute) {
                return false;
            }
            if (!$isModerator && !($isUser && $topic->getOwners()->contains($user))) {
                return false;
            }
        }

        $canRead = null === $category?->getRequiredRole() || $this->security->isGranted($category->getRequiredRole());

        switch ($attribute) {
            case self::READ:
                return $canRead;

            case self::POST:
                if (!$isUser || !$canRead || !$category instanceof Category || !$category->isBoard()) {
                    return false;
                }
                // What an announcement board says is the admins' to say, locked or not.
                if ($category->isAnnouncement()) {
                    return $this->security->isGranted($this->adminRole);
                }
                return !$category->isLocked() || $isModerator;

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

    /**
     * Whether holders of these roles moderate this board - or, with no board,
     * any board at all.
     *
     * The admin role moderates everything. Below it, the moderator role
     * moderates the open boards and the boards kept for a role it stands
     * ABOVE, never one kept for its own rank: the moderators read their own
     * board but the admins keep order there, while they do keep it on the
     * animators' and helpers'. A board kept for a role outside their branch
     * they cannot even read. Announcement boards are the admins' alone.
     *
     * @param string[] $roles
     */
    public function moderates(array $roles, ?Category $category): bool
    {
        $reachable = $this->roleHierarchy->getReachableRoleNames($roles);
        if (in_array($this->adminRole, $reachable, true)) {
            return true;
        }
        if (!in_array($this->moderatorRole, $reachable, true)) {
            return false;
        }
        if (null === $category) {
            return true;
        }
        if ($category->isAnnouncement()) {
            return false;
        }

        $required = $category->getRequiredRole();
        if (null === $required) {
            return true;
        }
        foreach ($reachable as $role) {
            $below = $this->roleHierarchy->getReachableRoleNames([$role]);
            if ($role !== $required && in_array($required, $below, true) && in_array($this->moderatorRole, $below, true)) {
                return true;
            }
        }

        return false;
    }
}
