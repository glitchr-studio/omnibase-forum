<?php

namespace App\Controller;

use App\Entity\User;
use Base\Entity\Thread\Tag;
use Base\Forum\Entity\Category;
use Base\Forum\Entity\Post;
use Base\Forum\Entity\Topic;
use Base\Forum\Security\ForumVoter;
use Base\Forum\Service\MarkdownRenderer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The demonstrator's one page: it seeds a small forum, signs a visitor in,
 * and reports on the wiring - then hands over to the forum itself.
 *
 * Everything here is demo scaffolding. A real application seeds boards from
 * a fixture or the admin, and signs members in through base-bundle's
 * security controllers; nothing in this file is a pattern to copy.
 */
class DemoController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MarkdownRenderer $markdown,
        private readonly Security $security,
    ) {
    }

    #[Route('/', name: 'demo_index')]
    public function index(): Response
    {
        $sections = [];
        $demo = function (string $title, string $about, callable $fn) use (&$sections) {
            try {
                $sections[] = ['title' => $title, 'about' => $about, 'ok' => true, 'output' => $fn()];
            } catch (\Throwable $e) {
                $sections[] = ['title' => $title, 'about' => $about, 'ok' => false, 'output' => $e::class.': '.$e->getMessage()];
            }
        };

        $demo('Seed', 'Two members, a group with two boards, three tags and a topic with replies - created once, then found again on every reload.', fn () => $this->seed());

        $demo('A Topic IS a Thread', 'The forum adds no title, slug, tag, owner or like of its own: a Topic is a JOINED subclass of base-bundle\'s Thread, and inherits all of it.', function () {
            $topic = $this->entityManager->getRepository(Topic::class)->findOneBy([], ['id' => 'ASC']);
            $parents = [];
            for ($c = new \ReflectionClass($topic); $c; $c = $c->getParentClass()) {
                $parents[] = $c->getName();
            }

            return implode("\n  extends ", $parents)
                ."\n\ntitle:   ".$topic->getTitle()
                ."\nslug:    ".$topic->getSlug()
                ."\nowner:   ".$topic->getAuthor()
                ."\ntags:    ".implode(', ', array_map('strval', $topic->getTags()->toArray()))
                ."\nstate:   ".$topic->getState()
                ."\nreplies: ".$topic->getReplies();
        });

        $demo('Markdown, with the raw HTML escaped', 'Posts are Markdown (league/commonmark), plus two forum habits: @pseudo links to a profile, >>12 to a post.', fn () => $this->markdown->render(
            "**Bold**, _italic_ and `code`.\n\n> A quote.\n\nHello @Chimbo, see >>1 — and <script>alert('nope')</script> stays text."
        ));

        $demo('Who may do what', 'One voter answers for boards, topics and posts; a board may also require a role to be read at all.', function () {
            $board = $this->entityManager->getRepository(Category::class)->findOneBy(['slug' => 'agora']);
            $staff = $this->entityManager->getRepository(Category::class)->findOneBy(['slug' => 'staff']);
            $topic = $this->entityManager->getRepository(Topic::class)->findOneBy([], ['id' => 'ASC']);

            $lines = [];
            foreach ([
                'FORUM_READ on the public board' => [ForumVoter::READ, $board],
                'FORUM_READ on the staff board (needs ROLE_ADMIN)' => [ForumVoter::READ, $staff],
                'FORUM_POST on the public board' => [ForumVoter::POST, $board],
                'FORUM_REPLY on the topic' => [ForumVoter::REPLY, $topic],
                'FORUM_EDIT on the topic (signed in as its author)' => [ForumVoter::EDIT, $topic],
                'FORUM_MODERATE' => [ForumVoter::MODERATE, null],
            ] as $label => [$attribute, $subject]) {
                $lines[] = sprintf('%-50s %s', $label, $this->isGranted($attribute, $subject) ? 'granted' : 'denied');
            }

            return implode("\n", $lines);
        });

        $demo('Boards and counters', 'What the index page reads: the two-level tree, and one query for the per-board topic and reply counts.', function () {
            $counts = $this->entityManager->getRepository(Topic::class)->countPerCategory();
            $lines = [];
            foreach ($this->entityManager->getRepository(Category::class)->findTree() as $group) {
                $lines[] = $group->getTitle();
                foreach ($group->getChildren() as $board) {
                    $c = $counts[$board->getId()] ?? ['topics' => 0, 'replies' => 0];
                    $lines[] = sprintf('  %-24s %d topic(s), %d repl(y|ies)%s', $board->getTitle(), $c['topics'], $c['replies'], $board->getRequiredRole() ? ' ['.$board->getRequiredRole().']' : '');
                }
            }

            return implode("\n", $lines);
        });

        return $this->render('demo/index.html.twig', ['sections' => $sections]);
    }

    /**
     * The seed. Idempotent on the slugs, so a reload finds what the first
     * load created instead of piling up boards.
     */
    private function seed(): string
    {
        $users = $this->entityManager->getRepository(User::class);
        $categories = $this->entityManager->getRepository(Category::class);
        $created = [];

        $me = $users->findOneBy(['username' => 'Marki']);
        if (!$me) {
            $me = $this->member('Marki', 'marki@example.org', ['ROLE_ADMIN']);
            $created[] = 'member Marki (ROLE_ADMIN)';
        }
        if (!$other = $users->findOneBy(['username' => 'Chimbo'])) {
            $other = $this->member('Chimbo', 'chimbo@example.org', ['ROLE_USER']);
            $created[] = 'member Chimbo';
        }

        if (!$group = $categories->findOneBy(['slug' => 'la-communaute'])) {
            $group = new Category('La communauté');
            $group->setIcon('fa-solid fa-comments')->setColor('#006699');
            $this->entityManager->persist($group);

            $agora = new Category('Agora', $group);
            $agora->setDescription('Le forum de la communauté.')->setPosition(10);
            $this->entityManager->persist($agora);

            $staff = new Category('Staff', $group);
            $staff->setDescription('Réservé à l\'équipe : un board qui demande un rôle pour être lu.')->setPosition(20)->setRequiredRole('ROLE_ADMIN');
            $this->entityManager->persist($staff);

            $created[] = 'group "La communauté" with the boards Agora and Staff';
        }

        $tags = [];
        foreach (['Question' => '#6090BE', 'Astuce' => '#018352', 'Bug' => '#FF3399'] as $label => $color) {
            $tag = $this->entityManager->getRepository(Tag::class)->findOneBy(['slug' => strtolower($label)]);
            if (!$tag) {
                $tag = new Tag($label);
                $tag->setColor($color);
                $this->entityManager->persist($tag);
                $created[] = 'tag '.$label;
            }
            $tags[] = $tag;
        }

        $this->entityManager->flush();

        if (!$this->entityManager->getRepository(Topic::class)->findOneBy([])) {
            $board = $categories->findOneBy(['slug' => 'agora']);
            $topic = new Topic($me, $board, 'Bienvenue sur le forum !');
            $topic->addTag($tags[0]);
            $topic->addPost(new Post($me, "Ce sujet a été écrit par le démonstrateur.\n\nLe **gras**, l'_italique_, les listes, les citations et le `code` marchent, et on peut interpeller quelqu'un avec @Chimbo."));
            $topic->addPost(new Post($other, "Et ça, c'est une réponse.\n\n> Le **gras**, l'_italique_…\n\nTout à fait."));
            $this->entityManager->persist($topic);
            $this->entityManager->flush();
            $created[] = 'topic "Bienvenue sur le forum !" with two posts';
        }

        // Signed in for the whole visit, so the forum shows its write side.
        if (!$this->getUser()) {
            $this->security->login($me, 'security.authenticator.form_login.main');
        }

        return $created ? "created:\n  - ".implode("\n  - ", $created) : 'nothing to do: the board was already seeded.';
    }

    private function member(string $username, string $email, array $roles): User
    {
        $user = new User();
        $user->setUsername($username);
        $user->setEmail($email);
        $user->setPlainPassword('demo');
        $user->setRoles($roles);
        $user->verify();
        $this->entityManager->persist($user);

        return $user;
    }
}
