<?php

namespace Base\Forum;

use Base\Bundle\AbstractBaseBundle;
use Base\Traits\SingletonTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * A discussion forum on top of base-bundle's Thread.
 *
 * A Topic IS a Thread (JOINED subclass), so it inherits what base-bundle
 * already gives every thread - tags, owners, followers, likes, mentions, a
 * slug, publish states and soft delete - and the forum only adds what a forum
 * needs on top: categories, posts, pins, locks and view counts.
 */
class ForumBundle extends AbstractBaseBundle
{
    // Gives this bundle its own singleton storage instead of sharing
    // AbstractBaseBundle's - see that class's constructor for why this is
    // required on every concrete bundle extending it, not just this one.
    use SingletonTrait;

    // The trait's own protected no-op __construct() takes priority over the
    // INHERITED AbstractBaseBundle::__construct() the moment the trait is
    // used directly here, which would make Symfony's `new ForumBundle()` in
    // bundles.php fatal (protected constructor). Re-declaring it explicitly,
    // public, delegating to parent, restores the registration logic.
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Modern bundle layout: the class lives in src/, the bundle root is the
     * package root - so TwigBundle picks up ./templates as @Forum and Symfony
     * picks up ./translations. Doctrine's auto_mapping still finds the
     * entities: for attribute mappings it looks next to the bundle CLASS,
     * i.e. src/Entity, with the prefix Base\Forum\Entity.
     */
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Same App\-wins override convention BaseBundle uses for its own
        // entities: an application may declare App\Entity\Forum\Topic
        // extending ours and take over; otherwise the alias points here.
        $this->setMapping($this->getPath() . '/src/Entity', 'Base\Forum\Entity', 'App\Entity\Forum');
        $this->setMapping($this->getPath() . '/src/Repository', 'Base\Forum\Repository', 'App\Repository\Forum');
    }
}
