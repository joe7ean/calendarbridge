<?php

declare(strict_types=1);

namespace OCP\Http\Client;

/**
 * @internal Test stub
 */
interface IResponse {
    public function getStatusCode(): int;

    public function getBody(): string;

    public function getHeader(string $name): ?string;
}
