<?php

namespace Base\Forum\Form\Type;

use Base\Entity\Thread\Tag;
use Base\Forum\Entity\Category;
use Base\Forum\Form\Model\TopicModel;
use Base\Forum\Repository\CategoryRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class TopicType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => TopicModel::class,
            // Says the domain for the form itself only. Every field below names
            // it in its keys ("@forum.form.title"), because base-bundle's form
            // extension gives each FIELD a translation_domain of its own -
            // "fields" - which is not null, so they never inherited this one:
            // "form.title" was looked up in "fields", found nothing, and the
            // labels, placeholders and help of the forum's forms came out blank.
            'translation_domain' => 'forum',
            // Only the opening post is edited through this type; on edit
            // the content field is left out (see TopicController::edit).
            'with_content' => true,
        ]);
        $resolver->setAllowedTypes('with_content', 'bool');
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => '@forum.form.title',
                'attr' => ['maxlength' => 120, 'placeholder' => '@forum.form.title_placeholder', 'autocomplete' => 'off'],
            ])
            ->add('category', EntityType::class, [
                'label' => '@forum.form.category',
                'class' => Category::class,
                'choice_label' => fn (Category $c) => ($c->getParent() ? $c->getParent()->getTitle() . ' › ' : '') . $c->getTitle(),
                'query_builder' => fn (CategoryRepository $r) => $r->createQueryBuilder('c')
                    ->innerJoin('c.parent', 'p')->addSelect('p')
                    ->andWhere('c.locked = false')
                    ->orderBy('p.position', \SortDirection::Ascending)->addOrderBy('c.position', \SortDirection::Ascending)->addOrderBy('c.title', \SortDirection::Ascending),
                'placeholder' => '@forum.form.category_placeholder',
            ])
            ->add('tags', EntityType::class, [
                'label' => '@forum.form.tags',
                'class' => Tag::class,
                'choice_label' => fn (Tag $t) => (string) $t,
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'help' => '@forum.form.tags_help',
                // A row of chips rather than a column of switches (forum.css).
                'attr' => ['class' => 'forum-tag-picker'],
            ]);

        if ($options['with_content']) {
            $builder->add('content', TextareaType::class, [
                'label' => '@forum.form.content',
                'attr' => ['rows' => 12, 'placeholder' => '@forum.form.content_placeholder', 'data-forum-editor' => ''],
                'help' => '@forum.form.markdown_help',
            ]);

            // The poll, only when the topic is opened: its answers are never
            // edited afterwards (a vote records which one it chose).
            $builder
                ->add('pollQuestion', TextType::class, [
                    'label' => '@forum.poll.form.question',
                    'required' => false,
                    'attr' => ['maxlength' => 200, 'placeholder' => '@forum.poll.form.question_placeholder', 'autocomplete' => 'off'],
                    'help' => '@forum.poll.form.question_help',
                ])
                ->add('pollOptions', TextareaType::class, [
                    'label' => '@forum.poll.form.options',
                    'required' => false,
                    'attr' => ['rows' => 5, 'placeholder' => '@forum.poll.form.options_placeholder'],
                    'help' => '@forum.poll.form.options_help',
                ]);
        }
    }
}
