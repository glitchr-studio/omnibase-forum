<?php

namespace Base\Forum\Tests\Entity;

use App\Entity\User;
use Base\Forum\Entity\Poll;
use Base\Forum\Entity\PollVote;
use Base\Forum\Entity\Topic;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;

/**
 * A poll that allows one answer, or several (Poll::$maxChoices): what a member picked, how many
 * voted, and an answer's share counted in VOTERS, not in picks.
 */
final class PollTest extends TestCase
{
    /** Built without their constructors: Thread's and User's want the kernel (translations, settings). */
    private static function bare(string $class): object
    {
        return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    }

    private function poll(int $max, int $answers = 4): Poll
    {
        $topic = self::bare(Topic::class);
        (new \ReflectionProperty(Topic::class, 'posts'))->setValue($topic, new ArrayCollection());

        return new Poll($topic, 'Le meilleur fan site ?', array_map(fn ($i) => 'Réponse '.$i, range(1, $answers)), $max);
    }

    public function testASinglePollIsTheOneItAlwaysWas(): void
    {
        $poll = $this->poll(1);

        self::assertFalse($poll->isMultiple());
        self::assertSame(1, $poll->getMaxChoices());
    }

    public function testSeveralCannotBeMoreThanThereAreAnswers(): void
    {
        self::assertSame(3, $this->poll(9, 3)->getMaxChoices());
        self::assertSame(1, $this->poll(0)->getMaxChoices());
    }

    public function testAVoterWithThreePicksIsOneVoter(): void
    {
        $poll = $this->poll(3);
        $marki = self::bare(User::class);
        $kamais = self::bare(User::class);
        foreach ([2, 0, 3] as $choice) {
            new PollVote($poll, $marki, $choice);
        }
        new PollVote($poll, $kamais, 0);

        self::assertTrue($poll->isMultiple());
        self::assertSame([0, 2, 3], $poll->getChoicesOf($marki), 'their picks, in order');
        self::assertSame([0], $poll->getChoicesOf($kamais));
        self::assertSame([], $poll->getChoicesOf(self::bare(User::class)), 'nobody else voted');
        self::assertSame(2, $poll->getTotal(), 'two voters, four picks');
        self::assertSame([2, 0, 1, 1], $poll->getCounts());
    }
}
