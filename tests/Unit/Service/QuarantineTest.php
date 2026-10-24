<?php

declare(strict_types=1);

namespace OCA\CalendarBridge\Tests\Unit\Service;

use OCA\CalendarBridge\Db\Feed;
use OCA\CalendarBridge\Service\Quarantine;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

class QuarantineTest extends TestCase {
    private int $now = 1_800_000_000;

    private function quarantine(): Quarantine {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueInt')->willReturnCallback(
            static fn (string $app, string $key, int $default) => $default,
        );
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturnCallback(fn () => $this->now);
        return new Quarantine($appConfig, $time);
    }

    public function testNormalDeletionsAreApplied(): void {
        $q = $this->quarantine();
        self::assertNull($q->reason(100, 9, 91));   // below the absolute minimum
        self::assertNull($q->reason(100, 40, 60));  // below the ratio
        self::assertSame(Quarantine::APPLY, $q->decide(new Feed(), 100, 40, 60, 'h'));
    }

    public function testEmptyFeedAndMassDeletionAreSuspicious(): void {
        $q = $this->quarantine();
        self::assertSame(Quarantine::REASON_EMPTY, $q->reason(3, 3, 0));
        self::assertSame(Quarantine::REASON_MASS_DELETE, $q->reason(100, 50, 50));
        self::assertSame(Quarantine::REASON_MASS_DELETE, $q->reason(12, 10, 2));
    }

    public function testSuspiciousResultIsHeldThenAutoAppliedAfterADayAndTwoSyncs(): void {
        $q = $this->quarantine();
        $feed = new Feed();

        self::assertSame(Quarantine::HOLD_NEW, $q->decide($feed, 10, 10, 0, 'empty-hash'));
        self::assertTrue($feed->isInQuarantine());
        self::assertSame(10, $feed->getQuarantineHeld());

        $this->now += 3600; // seen twice, but not a day yet
        self::assertSame(Quarantine::HOLD, $q->decide($feed, 10, 10, 0, 'empty-hash'));

        $this->now += 23 * 3600;
        self::assertSame(Quarantine::APPLY, $q->decide($feed, 10, 10, 0, 'empty-hash'));
        self::assertFalse($feed->isInQuarantine());
    }

    public function testChangedSuspiciousResultRestartsTheQuarantine(): void {
        $q = $this->quarantine();
        $feed = new Feed();
        $q->decide($feed, 10, 10, 0, 'h');
        $this->now += 3 * 86400;
        // Time has passed, but this is a different result: it must be confirmed itself.
        self::assertSame(Quarantine::HOLD_NEW, $q->decide($feed, 20, 15, 5, 'other'));
        self::assertSame(1, $feed->getQuarantineCount());
        self::assertSame($this->now, $feed->getQuarantineSince()->getTimestamp());
    }

    public function testRecoveredFeedEndsQuarantineWithoutDeleting(): void {
        $q = $this->quarantine();
        $feed = new Feed();
        $q->decide($feed, 10, 10, 0, 'h');
        self::assertSame(Quarantine::APPLY, $q->decide($feed, 10, 1, 10, 'normal'));
        self::assertFalse($feed->isInQuarantine());
    }

    public function testRejectedResultIsNeverAppliedAutomatically(): void {
        $q = $this->quarantine();
        $feed = new Feed();
        $q->decide($feed, 10, 10, 0, 'h');
        $feed->setRejectedHash('h');
        $this->now += 30 * 86400;
        self::assertSame(Quarantine::HOLD, $q->decide($feed, 10, 10, 0, 'h'));
        self::assertSame(Quarantine::HOLD, $q->decide($feed, 10, 10, 0, 'h'));
    }

    public function testAdminApprovalAppliesOnlyTheQuarantinedResult(): void {
        $q = $this->quarantine();
        $feed = new Feed();
        $q->decide($feed, 10, 10, 0, 'h');

        self::assertSame(Quarantine::HOLD_NEW, $q->decide($feed, 20, 15, 5, 'changed', true));
        self::assertSame(Quarantine::APPLY, $q->decide($feed, 20, 15, 5, 'changed', true));
    }

    public function testHashIgnoresOrderAndDuplicates(): void {
        self::assertSame(Quarantine::hashUids(['b', 'a', 'a']), Quarantine::hashUids(['a', 'b']));
        self::assertNotSame(Quarantine::hashUids(['a']), Quarantine::hashUids(['a', 'b']));
    }
}
