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
            // Says the domain for the form itself only. Every field below names
            // it in its keys ("@forum.form.title"), because base-bundle's form
            // extension gives each FIELD a translation_domain of its own -
            // "fields" - which is not null, so they never inherited this one:
            // "form.title" was looked up in "fields", found nothing, and the
            // labels, placeholders and help of the forum's forms came out blank.
            'translation_domain' => 'forum',
        ]);
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('content', TextareaType::class, [
            'label' => '@forum.form.reply',
            'attr' => ['rows' => 8, 'placeholder' => '@forum.form.reply_placeholder', 'data-forum-editor' => ''],
            'help' => '@forum.form.markdown_help',
        ]);
    }
}
