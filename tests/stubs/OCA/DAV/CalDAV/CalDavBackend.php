<?php

declare(strict_types=1);

namespace OCA\DAV\CalDAV;

/**
 * Minimal stub so PHPUnit can mock CalDavBackend without a full Nextcloud tree.
 * At runtime Nextcloud replaces this with the real implementation.
 */
class CalDavBackend {
    public function getCalendarsForUser(string $principalUri): array {
        return [];
    }

    public function createCalendar(string $principalUri, string $calendarUri, array $properties): int {
        return 0;
    }

    public function deleteCalendar(int $calendarId, bool $force = false): void {
    }

    /** @return list<array<string, mixed>> */
    public function getCalendarObjects(int $calendarId, int $calendarType = 1): array {
        return [];
    }

    /** @return array<string, mixed>|null */
    public function getCalendarObject(int $calendarId, string $objectUri, int $calendarType = 1): ?array {
        return null;
    }

    public function createCalendarObject(int $calendarId, string $objectUri, string $objectData): void {
    }

    public function updateCalendarObject(int $calendarId, string $objectUri, string $objectData): void {
    }

    public function deleteCalendarObject(int $calendarId, string $objectUri): void {
    }
}
