<?php

declare(strict_types=1);

namespace Dakiquang\OAuthRefreshLock\Stores;

use Dakiquang\OAuthRefreshLock\Contracts\TokenStore;
use Dakiquang\OAuthRefreshLock\Models\OAuthToken;
use Dakiquang\OAuthRefreshLock\StoredToken;

/**
 * Keeps tokens in a database table with the access and refresh tokens
 * encrypted by the application key. Compare-and-swap writes are a single
 * UPDATE ... WHERE version = ?, so they are safe across processes and servers.
 *
 * @phpstan-type Model class-string<OAuthToken>
 */
final class EloquentTokenStore implements TokenStore
{
    /**
     * @param  class-string<OAuthToken>  $model
     */
    public function __construct(private readonly string $model = OAuthToken::class) {}

    public function get(string $key): ?StoredToken
    {
        $row = $this->newModel()->newQuery()->where('key', $key)->first();

        return $row instanceof OAuthToken ? $this->toToken($row) : null;
    }

    public function put(string $key, StoredToken $token, ?int $expectedVersion = null): bool
    {
        $model = $this->newModel();
        // Let the model's casts encrypt and encode the values, then write them in one statement.
        $attributes = $model->newInstance()->forceFill($this->toAttributes($token))->getAttributes();
        $now = $model->freshTimestampString();

        if ($expectedVersion === null) {
            $model->newQuery()->upsert(
                [['key' => $key, ...$attributes, 'created_at' => $now, 'updated_at' => $now]],
                ['key'],
                [...array_keys($attributes), 'updated_at'],
            );

            return true;
        }

        return $model->newQuery()
            ->where('key', $key)
            ->where('version', $expectedVersion)
            ->update([...$attributes, 'updated_at' => $now]) === 1;
    }

    public function forget(string $key): void
    {
        $this->newModel()->newQuery()->where('key', $key)->delete();
    }

    private function newModel(): OAuthToken
    {
        return new ($this->model);
    }

    /**
     * @return array<string, mixed>
     */
    private function toAttributes(StoredToken $token): array
    {
        return [
            'access_token' => $token->accessToken,
            'refresh_token' => $token->refreshToken,
            'expires_at' => $token->expiresAt,
            'scopes' => $token->scopes,
            'meta' => $token->meta,
            'version' => $token->version,
            'revoked_at' => $token->revokedAt,
            'revoked_reason' => $token->revokedReason,
        ];
    }

    private function toToken(OAuthToken $row): StoredToken
    {
        return new StoredToken(
            accessToken: $row->access_token,
            refreshToken: $row->refresh_token,
            expiresAt: $row->expires_at,
            scopes: array_values($row->scopes ?? []),
            meta: $row->meta ?? [],
            version: $row->version,
            revokedAt: $row->revoked_at,
            revokedReason: $row->revoked_reason,
        );
    }
}
