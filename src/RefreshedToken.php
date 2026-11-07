<?php

declare(strict_types=1);

namespace Dakiquang\OAuthRefreshLock;

use Carbon\CarbonImmutable;

/**
 * What a Refresher returns after calling the provider's token endpoint.
 */
final class RefreshedToken
{
    /**
     * @param  string|null  $refreshToken  null when the provider did not issue a new refresh token.
     * @param  list<string>|null  $scopes  null keeps the scopes already stored.
     */
    public function __construct(
        public readonly string $accessToken,
        public readonly ?string $refreshToken,
        public readonly ?CarbonImmutable $expiresAt,
        public readonly ?array $scopes = null,
    ) {}

    /**
     * Build from an OAuth2 token response that reports `expires_in` seconds.
     *
     * @param  list<string>|null  $scopes
     */
    public static function fromExpiresIn(
        string $accessToken,
        ?string $refreshToken,
        ?int $expiresIn,
        ?CarbonImmutable $now = null,
        ?array $scopes = null,
    ): self {
        $now ??= CarbonImmutable::now();

        return new self(
            accessToken: $accessToken,
            refreshToken: $refreshToken,
            expiresAt: $expiresIn === null ? null : $now->addSeconds($expiresIn),
            scopes: $scopes,
        );
    }
}
