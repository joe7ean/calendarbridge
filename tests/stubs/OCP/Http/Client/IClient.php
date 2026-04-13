<?php

declare(strict_types=1);

namespace OCP\Http\Client;

/**
 * @internal Test stub
 */
interface IClient {
    /**
     * @param array<string, mixed> $options
     */
    public function get(string $url, array $options = []): IResponse;
}
