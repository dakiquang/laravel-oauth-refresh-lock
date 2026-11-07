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
     * when the stored version still equals $expectedVersion, and then stores the
     * token's own version.
     *
     * Without it (first connect, reconnect) the write is unconditional and the
     * store must move the version past whatever was stored before, ignoring the
     * token's version. Otherwise a process holding an older read could still win
     * a compare-and-swap against the new token.
     */
    public function put(string $key, StoredToken $token, ?int $expectedVersion = null): bool;

    public function forget(string $key): void;
}
