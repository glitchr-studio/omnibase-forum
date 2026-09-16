<?php

namespace Base\Forum\Controller\Admin;

use Base\Admin\Context\AdminContext;
use Base\Admin\Menu\MenuBuilder;
use Base\Entity\Thread\Tag;
use Base\Forum\Entity\Category;
use Base\Forum\Entity\Topic;
use Base\Forum\Repository\CategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The BBS's display order on one screen, the way a settings page reads:
 * the groups with their boards (a board can change group here too), and
 * the tags of the tag cloud. CategoryCrudController edits one board at a
 * time - ordering twenty of them by typing positions is what this spares.
 *
 * Categories are written 10, 20, 30... so a board added later through the
 * CRUD can still slot in between two without renumbering the rest. Tags
 * go the other way, highest first, because Tag::$priority is a priority:
 * the tag on top gets the largest value (TopicRepository::findTagUsage).
 */
#[IsGranted('ROLE_ADMIN')]
class OrderController extends AbstractController
{
    public const CSRF_TOKEN_ID = 'forum_admin_order';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CategoryRepository $categories,
        private readonly TranslatorInterface $translator,
        private readonly AdminContext $adminContext,
        private readonly MenuBuilder $menuBuilder,
    ) {
    }

    #[Route('/admin/bbs/ordre', name: 'forum_admin_order', methods: ['GET', 'POST'])]
    public function Order(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid token.');
            }

            $order = $request->request->all('order');
            $this->orderCategories((array) ($order['groups'] ?? []), (array) ($order['boards'] ?? []));
            $this->orderTags((array) ($order['tags'] ?? []));
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('admin.order.saved', [], 'forum'));

            return $this->redirectToRoute('forum_admin_order');
        }

        // Same seeding as base-bundle-admin's own non-CRUD pages (TrashController):
        // without it the layout renders an empty sidebar and no account menu.
        if ([] === $this->adminContext->getMainMenu()) {
            $this->adminContext->setMainMenu($this->menuBuilder->buildDefault());
        }
        if ([] === $this->adminContext->getUserMenu()) {
            $this->adminContext->setUserMenu($this->menuBuilder->buildUserMenuDefault($this->getUser()));
        }

        return $this->render('@Forum/admin/order.html.twig', [
            'admin_context' => $this->adminContext,
            'groups' => $this->categories->findTree(),
            'tags' => $this->entityManager->getRepository(Tag::class)->createQueryBuilder('tag')
                ->orderBy('tag.priority', \SortDirection::Descending)->addOrderBy('tag.slug', \SortDirection::Ascending)
                ->getQuery()->getResult(),
            'uses' => $this->tagUses(),
        ]);
    }

    /**
     * @param array<int|string>                   $groups ids of the groups, in order
     * @param array<int|string, array<int|string>> $boards group id => ids of its boards, in order
     */
    private function orderCategories(array $groups, array $boards): void
    {
        $all = [];
        foreach ($this->categories->findAll() as $category) {
            $all[$category->getId()] = $category;
        }

        foreach (array_values($groups) as $index => $id) {
            $group = $all[(int) $id] ?? null;
            if ($group?->isGroup()) {
                $group->setPosition(($index + 1) * 10);
            }
        }

        // A board stays a board and a group stays a group: the tree is two
        // levels deep, and a group with boards of its own cannot be filed
        // under another one. Only the parent of a board may change here.
        foreach ($boards as $groupId => $ids) {
            $group = $all[(int) $groupId] ?? null;
            if (!$group?->isGroup()) {
                continue;
            }
            foreach (array_values((array) $ids) as $index => $id) {
                $board = $all[(int) $id] ?? null;
                if (!$board?->isBoard()) {
                    continue;
                }
                if ($board->getParent() !== $group) {
                    $board->getParent()->getChildren()->removeElement($board);
                    $group->addChild($board);
                }
                $board->setPosition(($index + 1) * 10);
            }
        }
    }

    /** @param array<int|string> $ids the tags, the first one shown first */
    private function orderTags(array $ids): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) {
            return;
        }

        $tags = [];
        foreach ($this->entityManager->getRepository(Tag::class)->findBy(['id' => $ids]) as $tag) {
            $tags[$tag->getId()] = $tag;
        }
        foreach ($ids as $index => $id) {
            ($tags[$id] ?? null)?->setPriority((count($ids) - $index) * 10);
        }
    }

    /** @return array<int, int> tag id => how many topics carry it, so the admin sees what the cloud weighs */
    private function tagUses(): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('tag.id AS id, COUNT(t.id) AS nb')
            ->from(Tag::class, 'tag')
            ->innerJoin('tag.threads', 't')
            ->andWhere('t INSTANCE OF ' . Topic::class)
            ->groupBy('tag.id')
            ->getQuery()->getScalarResult();

        return array_column(array_map(fn ($row) => ['id' => (int) $row['id'], 'nb' => (int) $row['nb']], $rows), 'nb', 'id');
    }
}
