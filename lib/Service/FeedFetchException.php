<?php

declare(strict_types=1);

namespace OCA\CalendarBridge\Service;

class FeedFetchException extends \RuntimeException {
    /**
     * @param int|null $retryAfter seconds the source asked us to wait (HTTP Retry-After)
     */
    public function __construct(
        string $message,
        int $code = 0,
        ?\Throwable $previous = null,
        private readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getRetryAfter(): ?int {
        return $this->retryAfter;
    }
}
