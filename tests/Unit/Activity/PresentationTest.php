<?php

declare(strict_types=1);

namespace OCA\CalendarBridge\Tests\Unit\Activity;

use OCA\CalendarBridge\Activity\Provider;
use OCA\CalendarBridge\Activity\Setting;
use OCA\CalendarBridge\Db\Feed;
use OCA\CalendarBridge\Db\FeedMapper;
use OCA\CalendarBridge\Notification\Notifier;
use OCP\Activity\IEvent;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;

class PresentationTest extends TestCase {
    private function translations(): IFactory {
        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(static fn ($text, $args = []) => vsprintf($text, $args));
        $factory = $this->createMock(IFactory::class);
        $factory->method('get')->willReturn($l);
        return $factory;
    }

    public function testForwardedNotificationsIgnoreEvenLegacySensitiveParameters(): void {
        foreach (['feed_error', 'feed_quarantine', 'feed_notice'] as $subject) {
            $notification = $this->createMock(INotification::class);
            $notification->method('getApp')->willReturn('calendarbridge');
            $notification->method('getSubject')->willReturn($subject);
            $notification->expects(self::never())->method('getSubjectParameters');
            $notification->expects(self::once())->method('setParsedSubject')
                ->with('Calendar Bridge: Important calendar status update')->willReturnSelf();
            $notification->expects(self::once())->method('setParsedMessage')
                ->with('There is an important status update for a subscribed calendar. Sign in to Nextcloud to view the details.')->willReturnSelf();
            $notification->method('setLink')->willReturnSelf();
            $notifier = new Notifier($this->translations(), $this->createMock(IURLGenerator::class));
            $notifier->prepare($notification, 'en');
        }
    }

    public function testStreamResolvesCalendarNameOnlyForCurrentGroupMembers(): void {
        foreach ([true, false] as $hasAccess) {
            $feed = new Feed();
            $feed->setFeedName('Team calendar');
            $feed->setTargetGroupsArray(['team']);
            $feeds = $this->createMock(FeedMapper::class);
            $feeds->method('findById')->with(42)->willReturn($feed);
            $groups = $this->createMock(IGroupManager::class);
            $groups->method('isInGroup')->with('reader', 'team')->willReturn($hasAccess);
            $event = $this->createMock(IEvent::class);
            $event->method('getApp')->willReturn('calendarbridge');
            $event->method('getAffectedUser')->willReturn('reader');
            $event->method('getObjectId')->willReturn(42);
            $event->method('getSubject')->willReturn('summary');
            $event->method('getSubjectParameters')->willReturn(['added' => 1]);
            $event->expects(self::once())->method('setParsedSubject')->with(self::callback(
                static fn ($text) => str_contains($text, $hasAccess ? 'Team calendar' : 'feed #42')
                    && ($hasAccess || !str_contains($text, 'Team calendar')),
            ))->willReturnSelf();
            $event->method('setIcon')->willReturnSelf();
            (new Provider($this->translations(), $this->createMock(IURLGenerator::class), $feeds, $groups))->parse('en', $event);
        }
    }

    public function testActivityPreferencesCannotEnableSummaryMailOrPush(): void {
        $setting = new Setting($this->createMock(IL10N::class));
        self::assertFalse($setting->canChangeMail());
        self::assertFalse($setting->isDefaultEnabledMail());
        self::assertFalse($setting->canChangeNotification());
        self::assertFalse($setting->isDefaultEnabledNotification());
    }
}
