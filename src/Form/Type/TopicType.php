<?php

namespace Base\Forum\Form\Type;

use Base\Entity\Thread\Tag;
use Base\Forum\Entity\Category;
use Base\Forum\Form\Model\TopicModel;
use Base\Field\Type\SelectType;
use Base\Forum\Repository\CategoryRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
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
            // When it comes out, for those who may schedule (ForumVoter::SCHEDULE),
            // and whose name it goes out under, for the forum admin (ForumVoter::ADMIN).
            'with_schedule' => false,
            'with_author' => false,
            // Started from inside a board (/bbs/nouveau/<board>): the board is that one,
            // and there is nothing to choose - the field is left out.
            'fixed_category' => false,
            // The boards the writer may start a topic in (ForumVoter::POST), when the controller has
            // worked them out: the list then offers those only. Null, every open board.
            'boards' => null,
        ]);
        $resolver->setAllowedTypes('fixed_category', 'bool');
        $resolver->setAllowedTypes('boards', ['null', 'array']);
        $resolver->setAllowedTypes('with_content', 'bool');
        $resolver->setAllowedTypes('with_schedule', 'bool');
        $resolver->setAllowedTypes('with_author', 'bool');
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if (!$options['fixed_category']) {
            $builder
            ->add('category', EntityType::class, [
                'label' => '@forum.form.category',
                'class' => Category::class,
                'choice_label' => fn (Category $c) => ($c->getParent() ? $c->getParent()->getTitle() . ' › ' : '') . $c->getTitle(),
                'query_builder' => fn (CategoryRepository $r) => $r->createQueryBuilder('c')
                    ->innerJoin('c.parent', 'p')->addSelect('p')
                    ->andWhere('c.locked = false')
                    ->orderBy('p.position', \SortDirection::Ascending)->addOrderBy('c.position', \SortDirection::Ascending)->addOrderBy('c.title', \SortDirection::Ascending),
                'placeholder' => '@forum.form.category_placeholder',
            ] + (null !== $options['boards'] ? ['choices' => $options['boards']] : []) + [
                // Which boards take a poll: forum-poll.js shows the poll section for those only.
                'choice_attr' => fn (Category $c) => ['data-polls' => $c->allowsPolls() ? '1' : '0'],
            ]);
        }

        $builder
            ->add('title', TextType::class, [
                'label' => '@forum.form.title',
                'attr' => ['maxlength' => 120, 'placeholder' => '@forum.form.title_placeholder', 'autocomplete' => 'off'],
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
                // One answer per line: what the form sends. On the page, forum-poll-builder.js
                // draws it as a row per answer, each with the radio (or box) a voter will
                // see, and writes the lines back here; without the script, the textarea.
                ->add('pollOptions', TextareaType::class, [
                    'label' => '@forum.poll.form.options',
                    'required' => false,
                    'attr' => ['rows' => 5, 'placeholder' => '@forum.poll.form.options_placeholder', 'data-forum-poll-options' => ''],
                    'help' => '@forum.poll.form.options_help',
                ])
                // 1, a single answer; more, "several, up to N" - the script draws the choice.
                ->add('pollMax', IntegerType::class, [
                    'label' => '@forum.poll.form.max',
                    'required' => false,
                    'empty_data' => '1',
                    'attr' => ['min' => 1, 'max' => \Base\Forum\Entity\Poll::MAX_OPTIONS, 'data-forum-poll-max' => ''],
                    'help' => '@forum.poll.form.max_help',
                ]);
        }

        if ($options['with_author']) {
            // Searched, not listed: a select2 that asks base-bundle's autocomplete
            // for members as their name is typed - a list of every member does
            // not survive a forum with thousands of them. No faces (`avatar`):
            // a member without a picture came out as the full-size placeholder
            // image, which blew the field up to the height of a poster.
            $builder->add('author', SelectType::class, [
                'label' => '@forum.form.author',
                'class' => \App\Entity\User::class,
                'autocomplete' => true,
                'multiple' => false,
                'required' => false,
                'placeholder' => '@forum.form.author_placeholder',
                // A site may draw its members its own way (Chapaland: chibi and rank colour).
                'attr' => ['data-member-picker' => ''],
                'help' => '@forum.form.author_help',
            ]);
        }

        if ($options['with_schedule']) {
            // The browser's own date and time picker, read in the reader's own
            // timezone - base-bundle sets PHP's to their timezone cookie on every
            // request - and stored in UTC like every other date.
            $builder->add('publishedAt', DateTimeType::class, [
                'label' => '@forum.form.published_at',
                'required' => false,
                'widget' => 'single_text',
                'html5' => true,
                'input' => 'datetime',
                'help' => '@forum.form.published_at_help',
                'help_translation_parameters' => ['zone' => date_default_timezone_get()],
            ]);
        }
    }
}
