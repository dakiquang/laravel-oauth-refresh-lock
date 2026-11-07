<?php

declare(strict_types=1);

namespace Dakiquang\OAuthRefreshLock\Contracts;

use Dakiquang\OAuthRefreshLock\StoredToken;

interface TokenStore
{
    public function get(string $key): ?StoredToken;

    /**
     * Save a token.
     *
     * With $expectedVersion the write must be compare-and-swap: it only succeeds
     * when the stored version still equals $expectedVersion, and returns false
     * otherwise. Without it the token is written unconditionally (first connect).
     */
    public function put(string $key, StoredToken $token, ?int $expectedVersion = null): bool;

    public function forget(string $key): void;
}
