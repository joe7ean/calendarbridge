<?php

declare(strict_types=1);

namespace OCA\DAV\CalDAV;

use Sabre\DAV\PropPatch;

/**
 * Minimal stub so PHPUnit can mock CalDavBackend without a full Nextcloud tree.
 * At runtime Nextcloud provides the real implementation.
 *
 * Signatures and defaults mirror apps/dav/lib/CalDAV/CalDavBackend.php (NC 30–34);
 * keep them in sync when using a new method.
 */
class CalDavBackend {
    public const CALENDAR_TYPE_CALENDAR = 0;
    public const CALENDAR_TYPE_SUBSCRIPTION = 1;

    public function getCalendarsForUser($principalUri) {
        return [];
    }

    public function getCalendarById(int $calendarId): ?array {
        return null;
    }

    public function createCalendar($principalUri, $calendarUri, array $properties) {
        return 0;
    }

    public function updateCalendar($calendarId, PropPatch $propPatch) {
    }

    public function deleteCalendar($calendarId, bool $forceDeletePermanently = false) {
    }

    public function getCalendarObjects($calendarId, $calendarType = self::CALENDAR_TYPE_CALENDAR): array {
        return [];
    }

    public function getCalendarObject($calendarId, $objectUri, int $calendarType = self::CALENDAR_TYPE_CALENDAR) {
        return null;
    }

    public function getMultipleCalendarObjects($calendarId, array $uris, $calendarType = self::CALENDAR_TYPE_CALENDAR): array {
        return [];
    }

    public function createCalendarObject($calendarId, $objectUri, $calendarData, $calendarType = self::CALENDAR_TYPE_CALENDAR) {
        return null;
    }

    public function updateCalendarObject($calendarId, $objectUri, $calendarData, $calendarType = self::CALENDAR_TYPE_CALENDAR) {
        return null;
    }

    public function deleteCalendarObject($calendarId, $objectUri, $calendarType = self::CALENDAR_TYPE_CALENDAR, bool $forceDeletePermanently = false) {
    }
}
