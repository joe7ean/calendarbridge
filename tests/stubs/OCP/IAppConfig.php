<?php

declare(strict_types=1);

namespace OCP;

/**
 * @internal Test stub
 */
interface IAppConfig {
    public function getValueInt(string $appId, string $key, int $default): int;

    public function getValueBool(string $appId, string $key, bool $default): bool;
}
