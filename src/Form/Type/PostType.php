<?php

namespace Base\Forum\Form\Type;

use Base\Forum\Entity\Post;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** A reply, or the edit of one - just the Markdown body. */
class PostType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Post::class,
            'translation_domain' => 'forum',
            'validation_groups' => ['new', 'edit'],
        ]);
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('content', TextareaType::class, [
            'label' => 'form.reply',
            'attr' => ['rows' => 8, 'placeholder' => 'form.reply_placeholder', 'data-forum-editor' => ''],
            'help' => 'form.markdown_help',
        ]);
    }
}
