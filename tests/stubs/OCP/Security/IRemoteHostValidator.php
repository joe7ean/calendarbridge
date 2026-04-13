<?php

declare(strict_types=1);

namespace OCP\Security;

/**
 * @internal Test stub
 */
interface IRemoteHostValidator {
    public function isValid(string $host): bool;
}
