<?php

declare(strict_types=1);

namespace Dakiquang\OAuthRefreshLock\Stores;

use Dakiquang\OAuthRefreshLock\Contracts\TokenStore;
use Dakiquang\OAuthRefreshLock\StoredToken;

/**
 * In-memory store for tests and single-process scripts. Not shared between
 * processes, so it does not protect anything in production.
 */
final class ArrayTokenStore implements TokenStore
{
    /** @var array<string, StoredToken> */
    private array $tokens = [];

    public function get(string $key): ?StoredToken
    {
        return $this->tokens[$key] ?? null;
    }

    public function put(string $key, StoredToken $token, ?int $expectedVersion = null): bool
    {
        if ($expectedVersion !== null && ($this->tokens[$key] ?? null)?->version !== $expectedVersion) {
            return false;
        }

        $this->tokens[$key] = $token;

        return true;
    }

    public function forget(string $key): void
    {
        unset($this->tokens[$key]);
    }
}
