<?php

namespace Base\Forum\Form\Model;

use Base\Forum\Entity\Category;
use Base\Forum\Entity\Poll;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the "new topic" form collects: the topic's title, board and tags, and
 * the opening post's content - two entities, one form - and, when it is
 * given a question, the topic's poll.
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

    /** A poll is optional: no question, no poll. */
    #[Assert\Length(max: 200, maxMessage: '@forum.poll.question_long')]
    public ?string $pollQuestion = null;

    /** Its answers, one per line; blank lines are dropped. */
    public ?string $pollOptions = null;

    /** @return list<string> */
    public function pollOptionList(): array
    {
        $lines = preg_split('/\R/', (string) $this->pollOptions) ?: [];

        return array_values(array_filter(array_map(fn ($l) => mb_substr(trim($l), 0, 120), $lines), fn ($l) => $l !== ''));
    }

    public function hasPoll(): bool
    {
        return trim((string) $this->pollQuestion) !== '';
    }

    #[Assert\Callback]
    public function validatePoll(ExecutionContextInterface $context): void
    {
        $count = count($this->pollOptionList());
        if ($this->hasPoll() && ($count < Poll::MIN_OPTIONS || $count > Poll::MAX_OPTIONS)) {
            $context->buildViolation('@forum.poll.options_count')
                ->setParameter('{min}', (string) Poll::MIN_OPTIONS)->setParameter('{max}', (string) Poll::MAX_OPTIONS)
                ->atPath('pollOptions')->addViolation();
        } elseif (!$this->hasPoll() && $count > 0) {
            $context->buildViolation('@forum.poll.question_blank')->atPath('pollQuestion')->addViolation();
        }
    }
}
