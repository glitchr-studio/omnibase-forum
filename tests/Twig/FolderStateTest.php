<?php

namespace Tests\Base\Forum\Twig;

use Base\Forum\Entity\Category;
use Base\Forum\Entity\Topic;
use Base\Forum\Service\MarkdownRenderer;
use Base\Forum\Twig\ForumTwigExtension;
use PHPUnit\Framework\TestCase;

/**
 * Which folder badge a board or a topic wears: the precedence, and the
 * sleeping ("zzz") or talking art after forum.active_hours - here 72.
 */
class FolderStateTest extends TestCase
{
    private ForumTwigExtension $forum;

    protected function setUp(): void
    {
        $this->forum = new ForumTwigExtension(new MarkdownRenderer(), 25, 72);
    }

    /**
     * A stand-in with only what the extension reads: Thread's constructor goes
     * through the translation layer, which a unit test does not have.
     */
    private function topic(?Category $board = null, string $lastPost = '-10 days', int $replies = 0, bool $pinned = false, bool $locked = false): Topic
    {
        $topic = $this->createMock(Topic::class);
        $topic->method('getCategory')->willReturn($board ?? new Category('Agora', new Category('Chapaland')));
        $topic->method('getReplies')->willReturn($replies);
        $topic->method('getActivityAt')->willReturn(new \DateTime($lastPost));
        $topic->method('isPinned')->willReturn($pinned);
        $topic->method('isLocked')->willReturn($locked);

        return $topic;
    }

    public function testATopicSleepsOrTalksAfterItsLastMessage(): void
    {
        $this->assertSame('default', $this->forum->topicFolder($this->topic())['state']);
        $this->assertSame('new', $this->forum->topicFolder($this->topic(lastPost: '-2 hours'))['state']);
        $this->assertSame('default', $this->forum->topicFolder($this->topic(lastPost: '-73 hours'))['state']);
    }

    public function testPopularAndLockedTopicsHaveTheirAwakeArt(): void
    {
        $this->assertSame('hot', $this->forum->topicFolder($this->topic(replies: 30))['state']);
        $this->assertSame('new_hot', $this->forum->topicFolder($this->topic(lastPost: '-1 day', replies: 30))['state']);

        $this->assertSame('new_lock', $this->forum->topicFolder($this->topic(lastPost: '-1 day', replies: 30, locked: true))['state']);
    }

    public function testAnnouncementThenPostItComeFirst(): void
    {
        $news = (new Category('Annonce', new Category('Chapaland')))->setAnnouncement(true);
        $announcement = $this->topic($news, '-1 hour', 30, pinned: true, locked: true);
        $this->assertSame('announce', $this->forum->topicFolder($announcement)['state']);

        $pinned = $this->topic(lastPost: '-1 hour', replies: 30, pinned: true, locked: true);
        $this->assertSame('sticky', $this->forum->topicFolder($pinned)['state']);
    }

    public function testBoards(): void
    {
        $board = new Category('Agora', new Category('Chapaland'));
        $this->assertSame('default', $this->forum->boardFolder($board, null)['state']);
        $this->assertSame('new', $this->forum->boardFolder($board, $this->topic($board, '-3 hours'))['state']);
        $this->assertSame('new_hot', $this->forum->boardFolder($board, $this->topic($board, '-3 hours'), true)['state']);
        $this->assertSame('lock', $this->forum->boardFolder((clone $board)->setLocked(true))['state']);
    }

    public function testAReservedBoardIsNotAnAnnouncement(): void
    {
        $reserved = (new Category('BBS Modos', new Category('BBS Officiels')))->setRequiredRole('ROLE_MODERATOR');
        $folder = $this->forum->boardFolder($reserved);
        $this->assertSame('default', $folder['state']);
        $this->assertSame('@forum.board.private', $folder['title']);

        $news = (new Category('Messages Officiels', new Category('Chapaland')))->setAnnouncement(true)->setLocked(true);
        $this->assertSame('announce', $this->forum->boardFolder($news)['state']);
    }
}
