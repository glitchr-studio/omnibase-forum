<?php

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

/*
 * This file is part of the Glitchr package.
 *
 * (c) Marco Meyer <marco.meyer@glitchr.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * Everything in src/ is a plain autowired service, the way an application's
 * own src/ is - repositories get their doctrine.repository_service tag from
 * autoconfiguration, the Twig extension its twig.extension tag, the voter its
 * security.voter tag, controllers their controller.service_arguments tag.
 *
 * The backoffice CRUD controllers are loaded only when base-bundle-admin is
 * installed: they extend its AbstractCrudController, and without the class
 * the container could not even be compiled.
 */
return function (ContainerConfigurator $configurator) {

    $src = dirname(__DIR__) . '/src';

    $services = $configurator->services();
    $services->defaults()
        ->autowire(true)
        ->autoconfigure(true)
        ->public(false);

    $services->load('Base\\Forum\\', $src . '/')
        ->exclude([
            $src . '/DependencyInjection/',
            $src . '/Entity/',
            $src . '/Controller/Admin/',
            $src . '/ForumBundle.php',
        ]);

    $services->load('Base\\Forum\\Controller\\Client\\', $src . '/Controller/Client/')
        ->tag('controller.service_arguments');

    // Same layout as an application's src/Controller/Admin/Crud: the admin
    // route registry derives the slug from the class name.
    if (class_exists('Base\\Admin\\Controller\\AbstractCrudController')) {
        $services->load('Base\\Forum\\Controller\\Admin\\', $src . '/Controller/Admin/')
            ->tag('controller.service_arguments');
    }
};
