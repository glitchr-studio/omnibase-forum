<?php

namespace Base\Forum\DependencyInjection;

use Base\Bundle\AbstractBaseConfiguration;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

class ForumConfiguration extends AbstractBaseConfiguration
{
    /**
     * getTreeBuilder() memoises the builder while this method ADDS children
     * to it, so a second call would redeclare them - same guard as the
     * admin and wikidoc configurations.
     */
    private bool $childrenDeclared = false;

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = $this->getTreeBuilder();
        if ($this->childrenDeclared) {
            return $treeBuilder;
        }
        $this->childrenDeclared = true;

        $treeBuilder->getRootNode()
            ->children()
                ->integerNode('topics_per_page')->min(1)->defaultValue(20)
                    ->info('Topics listed per page in a category, a tag or the search.')->end()
                ->integerNode('posts_per_page')->min(1)->defaultValue(10)
                    ->info('Posts shown per page inside a topic.')->end()
                ->integerNode('flood_interval')->min(0)->defaultValue(15)
                    ->info('Seconds a member must wait between two posts (phpBB called it flood_interval).')->end()
                ->integerNode('hot_threshold')->min(1)->defaultValue(25)
                    ->info('Replies from which a topic is flagged "hot".')->end()
                ->integerNode('title_max_length')->min(10)->defaultValue(120)->end()
                ->integerNode('content_max_length')->min(100)->defaultValue(20000)->end()
                ->scalarNode('moderator_role')->defaultValue('ROLE_ADMIN')
                    ->info('Role allowed to pin, lock, move and delete any topic or post.')->end()
            ->end()
        ->end();

        return $treeBuilder;
    }
}
