# Forum

A discussion forum for [base-bundle](https://gitlab.glitchr.dev/public-repository/symfony/bundle/base/component):
boards, topics and Markdown posts, with tags, followers, likes, pins, locks and
moderation.

A `Topic` **is** a base-bundle `Thread` (a JOINED subclass), so it inherits what
every thread has - tags, owners, followers, likes, mentions, slug, publish
state, soft delete, translations - and the bundle only adds what a forum needs
on top: `Category` (a two-level tree: groups holding boards) and `Post`
(Markdown, rendered safely with league/commonmark).

## Install

```bash
composer require omnibase/forum:dev-main
```

```php
// config/bundles.php
Base\Forum\ForumBundle::class => ['all' => true],
```

```yaml
# config/routes.yaml
forum_controller:
    resource: "@ForumBundle/src/Controller/Client"
    type: attribute
    prefix: /
```

```yaml
# config/packages/forum.yaml (every key optional)
forum:
    topics_per_page: 20
    posts_per_page: 10
    flood_interval: 15        # seconds between two posts by the same member
    hot_threshold: 25
    moderator_role: ROLE_ADMIN
```

Then `bin/console doctrine:migrations:diff && bin/console doctrine:migrations:migrate`
and `bin/console assets:install` (the stylesheet lives in `public/css/forum.css`).

## Try it in one command

A self-contained demo (a bare Symfony skeleton, base-bundle, this checkout and SQLite) ships in the root [Dockerfile](Dockerfile). Its first page seeds a small board — two members, a group with a public and a staff-only board, three tags, a topic with a reply — signs you in, reports on the wiring (a Topic *is* a Thread, the Markdown renderer, what the voter grants), and links to the forum itself:

```bash
docker build -t base-bundle-forum-demo .
docker run --rm -p 8000:8000 base-bundle-forum-demo
# → http://localhost:8000/     the tour
# → http://localhost:8000/bbs  the forum
```

The demo app under [example/app/](example/app/) doubles as the minimal host: the `bundles.php`, `routes.yaml`, `doctrine.yaml`, `security.yaml` and `forum.yaml` an application needs, and a `layout1.html.twig` showing the only contract the forum's templates have with their host — `content`, `aside`, `title`, `stylesheets` and `javascripts` blocks.

## Development

```bash
make tests                               # phpunit, standalone or from inside a host app
docker compose run --rm test             # the same, in a clean php:8.4 container
docker compose run --rm test composer test-coverage   # → var/coverage/index.html
```

## Routes

| name | path |
|---|---|
| `forum_index` | `/bbs` |
| `forum_category` | `/bbs/c/{slug}` |
| `forum_tag` | `/bbs/t/{slug}` |
| `forum_search` | `/bbs/recherche?q=` |
| `forum_topic_new` | `/bbs/nouveau/{category?}` |
| `forum_topic` | `/bbs/{slug}` |
| `forum_topic_reply` | `POST /bbs/{slug}/repondre` |
| `forum_topic_edit` | `/bbs/{slug}/modifier` |
| `forum_topic_moderate` | `POST /bbs/{slug}/moderer/{pin,unpin,lock,unlock,delete}` |
| `forum_post_edit` | `/bbs/message/{id}/modifier` |
| `forum_post_delete` | `POST /bbs/message/{id}/supprimer` |

Likes and follows reuse base-bundle's `/api/thread/{slug}/{like,unlike,follow,unfollow}`.

## Override points

- `templates/bundles/ForumBundle/client/_banner.html.twig` — the banner above the forum.
- `templates/bundles/ForumBundle/client/_avatar.html.twig` — how a member is pictured.
- Any `App\Entity\Forum\Topic` / `Category` / `Post` class extending ours takes over (base-bundle's App-wins aliasing).

## Admin

base-bundle-admin is a requirement, not an option: base-bundle walks every class of an extension's `src/` when it builds its `App\` aliases, so CRUD controllers extending an admin class that is not installed would take the whole application down. With it, `Base\Forum\Controller\Admin\Crud\{Category,Topic}CrudController`
register themselves; link them from the dashboard with
`MenuItem::linkToCrud(\Base\Forum\Entity\Category::class, ...)`.
