<?php

declare(strict_types=1);

namespace OCA\CalendarBridge\Tests\Unit\Activity;

use OCA\CalendarBridge\Activity\ActivityService;
use OCA\CalendarBridge\Db\Feed;
use OCA\CalendarBridge\Notification\NotificationService;
use OCP\Activity\IEvent;
use OCP\Activity\IManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ActivityServiceTest extends TestCase {
    private int $now = 100000;
    private array $published = [];
    private Feed $feed;
    private ActivityService $service;
    private NotificationService $notifications;

    protected function setUp(): void {
        $this->feed = new Feed();
        $this->feed->setId(42);
        $this->feed->setFeedName('SECRET NAME');
        $this->feed->setFeedUrl('https://secret.invalid/?token=SECRET');
        $this->feed->setTargetGroupsArray(['one', 'two']);
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('reader');
        $group = $this->createMock(IGroup::class);
        $group->method('getUsers')->willReturn([$user]);
        $groups = $this->createMock(IGroupManager::class);
        $groups->method('get')->willReturn($group);
        $manager = $this->createMock(IManager::class);
        $manager->method('generateEvent')->willReturnCallback(function () {
            $event = $this->createMock(IEvent::class);
            foreach (['setApp', 'setType', 'setAuthor', 'setAffectedUser', 'setTimestamp', 'setObject'] as $method) {
                $event->method($method)->willReturnSelf();
            }
            $event->method('setSubject')->willReturnCallback(function ($subject, $parameters) use ($event) {
                $this->published[] = [$subject, $parameters];
                return $event;
            });
            return $event;
        });
        $clock = $this->createMock(ITimeFactory::class);
        $clock->method('getTime')->willReturnCallback(fn () => $this->now);
        $this->notifications = $this->createMock(NotificationService::class);
        $this->service = new ActivityService($manager, $groups, $this->notifications, $clock, $this->createMock(LoggerInterface::class));
    }

    public function testDailySummaryAccumulatesDeduplicatesAndContainsOnlyCounts(): void {
        $this->notifications->expects(self::never())->method('notifyStatus');
        $this->service->success($this->feed, ['added' => 2, 'updated' => 1], false, false);
        $this->now += 3600;
        $this->service->success($this->feed, ['deleted' => 3], false, false);
        $this->service->flush($this->feed);
        self::assertSame([], $this->published);
        $this->now += 86400;
        $this->service->flush($this->feed);
        $this->service->flush($this->feed);
        self::assertSame([['summary', ['added' => 2, 'updated' => 1, 'deleted' => 3]]], $this->published);
        self::assertStringNotContainsString('SECRET', $this->feed->getActivityState());
    }

    public function testUnchangedFeedIsSilent(): void {
        $this->service->success($this->feed, [], false, false);
        $this->now += 86400;
        $this->service->flush($this->feed);
        self::assertSame([], $this->published);
    }

    public function testRemovedGroupsReceiveNoPendingSummary(): void {
        $this->service->success($this->feed, ['added' => 2], false, false);
        $this->feed->setTargetGroupsArray([]);
        $this->now += 86400;
        $this->service->flush($this->feed);
        self::assertSame([], $this->published);
    }

    public function testOutageAndRecoveryAreSentOnceIncludingPausedFeeds(): void {
        $this->notifications->expects(self::exactly(2))->method('notifyStatus')->with($this->feed, ['reader']);
        $this->service->failure($this->feed);
        $this->feed->setConsecutiveErrors(2);
        $this->now += 86400;
        $this->service->flush($this->feed);
        self::assertSame([], $this->published);
        $this->feed->setConsecutiveErrors(10);
        $this->feed->setActive(0);
        $this->service->flush($this->feed);
        $this->service->flush($this->feed);
        $this->service->success($this->feed, [], false, false);
        $this->service->success($this->feed, [], false, false);
        self::assertSame([['outage', []], ['recovered', []]], $this->published);
    }

    public function testQuarantineDistinguishesAdminFromAutomaticApproval(): void {
        $this->feed->setQuarantineHash('hash');
        $this->service->success($this->feed, [], false, false);
        $this->service->success($this->feed, [], true, false);
        $this->feed->clearQuarantine();
        $this->service->success($this->feed, ['deleted' => 10], true, true, true);
        $this->service->success($this->feed, ['deleted' => 12], true, false, true);
        self::assertSame([
            ['held', []], ['applied_admin', ['deleted' => 10]], ['applied_auto', ['deleted' => 12]],
        ], $this->published);
    }

    public function testChangedSourceIsNotMisreportedAsConfirmedHeldRemovals(): void {
        $this->service->success($this->feed, ['deleted' => 1], true, true, false);
        self::assertSame([['released', []]], $this->published);
    }
}
