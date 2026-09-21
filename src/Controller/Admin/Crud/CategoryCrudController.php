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
use Base\Field\SelectField;
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
        // An announcement board: it and its topics wear the megaphone badge, and the feed heads with them.
        yield BooleanField::new('announcement')->setColumns(3);
        yield IconField::new('icon')->setColumns(6)->hideOnIndex();
        yield ColorPickerField::new('color')->setColumns(3)->hideOnIndex();
        /*
         * The rank a board is reserved to, or none: anyone reads it. A plain
         * nullable string, not a roles column - RoleType guesses its choices
         * from a user_role mapping, found none here, and threw on both the
         * "new" and the "edit" page ("No choices ... could be guessed"), so
         * no board could be opened or changed from the back office. The
         * choices are the roles of the class RoleField itself settled on
         * (the application's own, when it declares one).
         */
        $requiredRole = RoleField::new('requiredRole');
        $roles = $requiredRole->getAsDto()->getCustomOption(SelectField::OPTION_ENUM_CLASS);
        yield $requiredRole->setChoices($roles::getPermittedValues())->setRequired(false)->setColumns(3)->hideOnIndex();
        yield TextareaField::new('description')->hideOnIndex();
    }
}
