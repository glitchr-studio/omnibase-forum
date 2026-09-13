<?php

namespace Base\Forum\Form\Type;

use Base\Forum\Form\Model\PostModel;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** A reply, or the edit of one - just the Markdown body (see PostModel). */
class PostType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PostModel::class,
            'translation_domain' => 'forum',
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
