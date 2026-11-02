<?php

declare(strict_types=1);

namespace OCA\CalendarBridge\Tests\Unit\Activity;

use OCA\CalendarBridge\Activity\PrivacyManager;
use OCA\CalendarBridge\Activity\PrivacyScope;
use OCA\CalendarBridge\Db\Feed;
use OCA\CalendarBridge\Db\FeedMapper;
use OCP\Activity\IEvent;
use OCP\Activity\IManager;
use OCP\Activity\ISetting;
use PHPUnit\Framework\TestCase;

class PrivacyManagerTest extends TestCase {
    public function testRelayDetailsNeverReachAnyConsumerButOtherCalendarsDo(): void {
        $feed = new Feed();
        $feed->setCalendarId(42);
        $feeds = $this->createMock(FeedMapper::class);
        $feeds->method('findAll')->willReturn([$feed]);
        $inner = $this->createMock(IManager::class);
        $inner->expects(self::once())->method('publish')->with(self::callback(fn ($e) => $e->getObjectId() === 43));
        $inner->expects(self::never())->method('bulkPublish');
        $manager = new PrivacyManager($inner, $feeds, new PrivacyScope());
        foreach ([42, 43] as $id) {
            $event = $this->createMock(IEvent::class);
            $event->method('getApp')->willReturn('dav');
            $event->method('getObjectType')->willReturn('calendar');
            $event->method('getObjectId')->willReturn($id);
            $event->method('getSubjectParameters')->willReturn(['object' => ['name' => 'SECRET']]);
            $manager->publish($event);
            if ($id === 42) {
                $manager->bulkPublish($event, ['reader'], $this->createMock(ISetting::class));
            }
        }
    }

    public function testCreationScopeIsRestoredAfterFailure(): void {
        $scope = new PrivacyScope();
        $inner = $this->createMock(IManager::class);
        $inner->expects(self::never())->method('publish');
        $manager = new PrivacyManager($inner, $this->createMock(FeedMapper::class), $scope);
        $event = $this->createMock(IEvent::class);
        $event->method('getApp')->willReturn('dav');
        $event->method('getObjectType')->willReturn('calendar');
        try {
            $scope->run(function () use ($scope, $manager, $event): void {
                self::assertTrue($scope->isActive());
                $manager->publish($event);
                throw new \RuntimeException('failed');
            });
        } catch (\RuntimeException) {
        }
        self::assertFalse($scope->isActive());
    }

    public function testOwnSummariesAndOtherAppsAreNotSuppressed(): void {
        $inner = $this->createMock(IManager::class);
        $inner->expects(self::exactly(2))->method('publish');
        $feeds = $this->createMock(FeedMapper::class);
        $feeds->expects(self::never())->method('findAll');
        $manager = new PrivacyManager($inner, $feeds, new PrivacyScope());
        foreach (['calendarbridge', 'files'] as $app) {
            $event = $this->createMock(IEvent::class);
            $event->method('getApp')->willReturn($app);
            $manager->publish($event);
        }
    }
}
