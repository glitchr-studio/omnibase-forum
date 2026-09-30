<?php

namespace Base\Forum\Form\Model;

use Base\Forum\Entity\Category;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the "new board" form collects (ForumController::CategoryNew): the name,
 * the group it goes in - none makes it a group of its own - and what it is for.
 */
class CategoryModel
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 128)]
    public ?string $title = null;

    public ?Category $parent = null;

    public ?string $description = null;

    /** A board of announcements: it and its topics wear the megaphone badge. */
    public bool $announcement = false;

    /** Whether topics opened in it may carry a poll. */
    public bool $polls = true;
}
