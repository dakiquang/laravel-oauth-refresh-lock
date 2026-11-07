<?php

declare(strict_types=1);

namespace Dakiquang\OAuthRefreshLock\Stores;

use Carbon\CarbonImmutable;
use Dakiquang\OAuthRefreshLock\Contracts\TokenStore;
use Dakiquang\OAuthRefreshLock\Models\OAuthToken;
use Dakiquang\OAuthRefreshLock\StoredToken;
use Dakiquang\OAuthRefreshLock\Support\Versions;

/**
 * Keeps tokens in a database table with the access and refresh tokens
 * encrypted by the application key. Every write is a single statement, so it
 * is safe across processes and servers and inside callers' transactions:
 * - compare-and-swap: UPDATE ... WHERE key = ? AND version = ?
 * - unconditional:    INSERT ... ON CONFLICT/DUPLICATE KEY UPDATE ..., version = version + 1
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
        $query = $model->newQuery()->toBase();
        $grammar = $query->getGrammar();
        /** @var literal-string $increment Only identifiers quoted by the grammar; no input reaches it. */
        $increment = $grammar->wrapTable($model->getTable()).'.'.$grammar->wrap('version').' + 1';
        $nextVersion = $model->getConnection()->raw($increment);

        $query->upsert(
            [[...$attributes, 'key' => $key, 'version' => Versions::initial(), 'created_at' => $now, 'updated_at' => $now]],
            ['key'],
            [...array_keys($attributes), 'updated_at', 'version' => $nextVersion],
        );

        return true;
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
            'revoked_from_version' => $token->revokedFromVersion,
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
            revokedFromVersion: $row->revoked_from_version,
        );
    }
}
