<?php

declare(strict_types=1);

namespace OCA\CalendarBridge\Activity;

/** Request-local protection for calendar creation before its feed ID is persisted. */
class PrivacyScope {
    private int $depth = 0;

    public function run(callable $operation): mixed {
        $this->depth++;
        try {
            return $operation();
        } finally {
            $this->depth--;
        }
    }

    public function isActive(): bool {
        return $this->depth > 0;
    }
}
