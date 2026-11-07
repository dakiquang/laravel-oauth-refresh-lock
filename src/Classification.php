<?php

declare(strict_types=1);

namespace Dakiquang\OAuthRefreshLock;

/**
 * What a refresh failure means, plus a short secret-free code describing it
 * (`invalid_grant`, `http_503`, `network_error`). The code is what gets stored
 * as the revocation reason and shown in exception messages; raw provider
 * messages and response bodies never are.
 */
final class Classification
{
    public function __construct(
        public readonly RefreshFailure $failure,
        public readonly string $code,
    ) {}

    public static function revoked(string $code): self
    {
        return new self(RefreshFailure::Revoked, $code);
    }

    public static function transient(string $code): self
    {
        return new self(RefreshFailure::Transient, $code);
    }

    public static function unexpected(string $code = 'unexpected'): self
    {
        return new self(RefreshFailure::Unexpected, $code);
    }
}
