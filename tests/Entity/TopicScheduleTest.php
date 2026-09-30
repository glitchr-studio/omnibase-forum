<?php

namespace Base\Forum\Tests\Entity;

use Base\Forum\Entity\Post;
use Doctrine\Common\Collections\ArrayCollection;
use Base\Forum\Entity\Topic;
use PHPUnit\Framework\TestCase;

/**
 * A topic written ahead of its hour (Topic::schedule()): upcoming until then,
 * its last activity and its opening post's date moved to that hour, and "now"
 * for any hour already gone.
 */
final class TopicScheduleTest extends TestCase
{
    /**
     * A topic as its constructor leaves it - out now - built without it:
     * Thread's constructor sets a translated title, and translations need the
     * kernel.
     */
    private function topic(): Topic
    {
        $topic = (new \ReflectionClass(Topic::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(Topic::class, 'posts'))->setValue($topic, new ArrayCollection());
        $topic->setPublishedAt(new \DateTime());
        $topic->addPost(new Post(null, 'Le premier message.'));

        return $topic;
    }

    public function testANewTopicIsOutAtOnce(): void
    {
        $topic = $this->topic();

        self::assertFalse($topic->isUpcoming());
        self::assertNotNull($topic->getPublishedAt());
    }

    public function testAnHourToComeKeepsItUpcomingAndMovesItsActivityThere(): void
    {
        $at = new \DateTime('+2 hours');
        $topic = $this->topic()->schedule($at);

        self::assertTrue($topic->isUpcoming());
        self::assertEquals($at, $topic->getPublishedAt());
        self::assertEquals($at, $topic->getLastPostAt(), 'the lists sort by it: the topic arrives at its hour');
    }

    public function testAnHourAlreadyGoneMeansNow(): void
    {
        $topic = $this->topic()->schedule(new \DateTime('-1 day'));

        self::assertFalse($topic->isUpcoming());
        self::assertEqualsWithDelta(time(), $topic->getPublishedAt()->getTimestamp(), 5);
    }

    public function testNoHourMeansNow(): void
    {
        $topic = $this->topic()->schedule(new \DateTime('+1 day'))->schedule(null);

        self::assertFalse($topic->isUpcoming(), 'emptying the date on an edit publishes it');
    }

    public function testTheOpeningPostIsDatedTheHourItsTopicComesOut(): void
    {
        $topic = $this->topic();
        $post = $topic->getFirstPost();
        // Persisted (createdAt, a #[Timestamp]) just after the topic was built (publishedAt).
        $written = (clone $topic->getPublishedAt())->modify('+1 second');
        (new \ReflectionProperty(Post::class, 'createdAt'))->setValue($post, $written);

        self::assertEquals($written, $post->getShownAt(), 'out at once: dated when written');

        $at = new \DateTime('+3 hours');
        $topic->schedule($at);
        self::assertEquals($at, $post->getShownAt());
    }

    public function testAReplyKeepsItsOwnDate(): void
    {
        $topic = $this->topic()->schedule(new \DateTime('+3 hours'));
        $reply = new Post(null, 'Une réponse.');
        $topic->addPost($reply);
        $written = new \DateTime('-1 minute');
        (new \ReflectionProperty(Post::class, 'createdAt'))->setValue($reply, $written);

        self::assertEquals($written, $reply->getShownAt());
    }
}
