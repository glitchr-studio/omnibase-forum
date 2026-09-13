<?php

namespace Base\Forum\Controller\Admin\Crud;

use Base\Admin\Controller\AbstractCrudController;
use Base\Admin\Filter\Filters;
use Base\Field\AssociationField;
use Base\Field\BooleanField;
use Base\Field\IdField;
use Base\Field\NumberField;
use Base\Field\SlugField;
use Base\Field\TranslationField;
use Base\Forum\Entity\Topic;

/** Admin CRUD for the topics. Posts are moderated on the site itself. */
class TopicCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Topic::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-comment-dots';
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('category')->add('pinned')->add('locked');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TranslationField::new()->setFields(['title' => []]);
        yield SlugField::new('slug')->setColumns(6)->hideOnIndex();
        yield AssociationField::new('category')->setColumns(6);
        yield AssociationField::new('tags')->setColumns(6)->hideOnIndex();
        yield BooleanField::new('pinned')->setColumns(3);
        yield BooleanField::new('locked')->setColumns(3);
        yield NumberField::new('views')->setColumns(3)->onlyOnIndex();
        yield NumberField::new('replies')->setColumns(3)->onlyOnIndex();
    }
}
