<?php

namespace Base\Forum\DependencyInjection;

use Base\Bundle\AbstractBaseExtension;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

class ForumExtension extends AbstractBaseExtension
{
    public function getConfiguration(array $config, ContainerBuilder $container): ForumConfiguration
    {
        return new ForumConfiguration();
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        // Package root, not src/Resources/config: modern layout, like wikidoc.
        // PhpFileLoader (not Xml): Symfony 8 removed XmlFileLoader.
        $loader = new PhpFileLoader($container, new FileLocator(dirname(__DIR__, 2) . '/config'));
        $loader->load('services.php');

        $processor = new Processor();
        $configuration = new ForumConfiguration();
        $config = $processor->processConfiguration($configuration, $configs);

        // Exposed as flat parameters: forum.topics_per_page, forum.posts_per_page, ...
        $this->setConfiguration($container, $config, $configuration->getTreeBuilder()->buildTree()->getName());
    }
}
