<?php

declare(strict_types=1);

namespace Dakiquang\OAuthRefreshLock\Stores;

use Carbon\CarbonImmutable;
use Dakiquang\OAuthRefreshLock\Contracts\TokenStore;
use Dakiquang\OAuthRefreshLock\Models\OAuthToken;
use Dakiquang\OAuthRefreshLock\StoredToken;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Keeps tokens in a database table with the access and refresh tokens
 * encrypted by the application key. Every write is a single statement, so it
 * is safe across processes and servers:
 * - compare-and-swap: UPDATE ... WHERE key = ? AND version = ?
 * - unconditional:    UPDATE ... SET version = version + 1, or INSERT for a new key
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

        if ($expectedVersion !== null) {
            return $model->newQuery()
                ->where('key', $key)
                ->where('version', $expectedVersion)
                ->update([...$attributes, 'updated_at' => $now]) === 1;
        }

        unset($attributes['version']);
        $bumped = [...$attributes, 'version' => $model->getConnection()->raw('version + 1'), 'updated_at' => $now];

        if ($this->bump($model, $key, $bumped)) {
            return true;
        }

        try {
            $model->newQuery()->insert([...$attributes, 'key' => $key, 'version' => $token->version, 'created_at' => $now, 'updated_at' => $now]);
        } catch (UniqueConstraintViolationException) {
            // Another process inserted the same key between our UPDATE and INSERT.
            return $this->bump($model, $key, $bumped);
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $values
     *
     * @phpstan-impure
     */
    private function bump(OAuthToken $model, string $key, array $values): bool
    {
        return $model->newQuery()->where('key', $key)->update($values) === 1;
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
            'expires_at' => $this->appTime($token->expiresAt),
            'scopes' => $token->scopes,
            'meta' => $token->meta,
            'version' => $token->version,
            'revoked_at' => $this->appTime($token->revokedAt),
            'revoked_reason' => $token->revokedReason,
        ];
    }

    /**
     * Laravel's datetime casts drop the offset when storing and read values back
     * in the default timezone, so convert first or the instant would shift.
     */
    private function appTime(?CarbonImmutable $time): ?CarbonImmutable
    {
        return $time?->setTimezone(date_default_timezone_get());
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
