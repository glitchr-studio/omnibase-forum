<?php

namespace Base\Forum\Controller\Admin\Crud;

use Base\Admin\Controller\AbstractCrudController;
use Base\Admin\Filter\Filters;
use Base\Field\AssociationField;
use Base\Field\BooleanField;
use Base\Field\ColorPickerField;
use Base\Field\IconField;
use Base\Field\IdField;
use Base\Field\NumberField;
use Base\Field\RoleField;
use Base\Field\SlugField;
use Base\Field\TextareaField;
use Base\Field\TextField;
use Base\Forum\Entity\Category;

/** Admin CRUD for the boards. */
class CategoryCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Category::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-comments';
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('parent')->add('locked');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('title')->setColumns(6);
        yield SlugField::new('slug')->setColumns(6)->hideOnIndex();
        yield AssociationField::new('parent')->setColumns(6);
        yield NumberField::new('position')->setColumns(3);
        yield BooleanField::new('locked')->setColumns(3);
        yield IconField::new('icon')->setColumns(6)->hideOnIndex();
        yield ColorPickerField::new('color')->setColumns(3)->hideOnIndex();
        yield RoleField::new('requiredRole')->setColumns(3)->hideOnIndex();
        yield TextareaField::new('description')->hideOnIndex();
    }
}
