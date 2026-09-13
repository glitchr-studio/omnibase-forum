<?php

namespace Base\Forum\Form\Model;

use Base\Forum\Entity\Category;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the "new topic" form collects: the topic's title, board and tags, and
 * the opening post's content - two entities, one form.
 */
class TopicModel
{
    #[Assert\NotBlank(message: '@forum.topic.title.blank')]
    #[Assert\Length(min: 3, max: 120, minMessage: '@forum.topic.title.short', maxMessage: '@forum.topic.title.long')]
    public ?string $title = null;

    #[Assert\NotNull(message: '@forum.topic.category.blank')]
    public ?Category $category = null;

    /** @var iterable<\Base\Entity\Thread\Tag> */
    public iterable $tags = [];

    #[Assert\NotBlank(message: '@forum.post.content.blank')]
    #[Assert\Length(min: 2, max: 20000, minMessage: '@forum.post.content.short', maxMessage: '@forum.post.content.long')]
    public ?string $content = null;
}
