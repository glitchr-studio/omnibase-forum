<?php

namespace Base\Forum\Form\Type;

use Base\Forum\Entity\Category;
use Base\Forum\Form\Model\CategoryModel;
use Base\Forum\Repository\CategoryRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A board opened from the BBS itself (ForumController::CategoryNew): its name,
 * the group it goes in, a line to say what it is for. Left without a group, it
 * is a new group. The back office's CRUD has the rest - icon, colour, the rank
 * a board is reserved to, the lock.
 */
class CategoryType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CategoryModel::class,
            // The keys carry their domain ("@forum.form..."): see TopicType.
            'translation_domain' => 'forum',
        ]);
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => '@forum.form.board_title',
                'attr' => ['maxlength' => 128, 'placeholder' => '@forum.form.board_title_placeholder', 'autocomplete' => 'off'],
            ])
            ->add('parent', EntityType::class, [
                'label' => '@forum.form.board_parent',
                'class' => Category::class,
                'choice_label' => fn (Category $c) => $c->getTitle(),
                // Groups only: the tree is two levels deep, and a topic lives in a board, never in a group.
                'query_builder' => fn (CategoryRepository $r) => $r->createQueryBuilder('c')
                    ->andWhere('c.parent IS NULL')
                    ->orderBy('c.position', \SortDirection::Ascending)->addOrderBy('c.title', \SortDirection::Ascending),
                'required' => false,
                'placeholder' => '@forum.form.board_parent_none',
                'help' => '@forum.form.board_parent_help',
            ])
            ->add('description', TextareaType::class, [
                'label' => '@forum.form.board_description',
                'required' => false,
                'attr' => ['rows' => 3, 'placeholder' => '@forum.form.board_description_placeholder'],
            ])
            ->add('announcement', CheckboxType::class, [
                'label' => '@forum.form.board_announcement',
                'required' => false,
                'help' => '@forum.form.board_announcement_help',
            ])
            ->add('polls', CheckboxType::class, [
                'label' => '@forum.form.board_polls',
                'required' => false,
                'help' => '@forum.form.board_polls_help',
            ]);
    }
}
