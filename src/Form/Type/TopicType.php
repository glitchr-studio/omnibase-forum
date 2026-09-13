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
                'label' => 'form.title',
                'attr' => ['maxlength' => 120, 'placeholder' => 'form.title_placeholder', 'autocomplete' => 'off'],
            ])
            ->add('category', EntityType::class, [
                'label' => 'form.category',
                'class' => Category::class,
                'choice_label' => fn (Category $c) => ($c->getParent() ? $c->getParent()->getTitle() . ' › ' : '') . $c->getTitle(),
                'query_builder' => fn (CategoryRepository $r) => $r->createQueryBuilder('c')
                    ->innerJoin('c.parent', 'p')->addSelect('p')
                    ->andWhere('c.locked = false')
                    ->orderBy('p.position', 'ASC')->addOrderBy('c.position', 'ASC')->addOrderBy('c.title', 'ASC'),
                'placeholder' => 'form.category_placeholder',
            ])
            ->add('tags', EntityType::class, [
                'label' => 'form.tags',
                'class' => Tag::class,
                'choice_label' => fn (Tag $t) => (string) $t,
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'help' => 'form.tags_help',
            ]);

        if ($options['with_content']) {
            $builder->add('content', TextareaType::class, [
                'label' => 'form.content',
                'attr' => ['rows' => 12, 'placeholder' => 'form.content_placeholder', 'data-forum-editor' => ''],
                'help' => 'form.markdown_help',
            ]);
        }
    }
}
