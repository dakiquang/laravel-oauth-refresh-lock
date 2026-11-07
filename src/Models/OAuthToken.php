<?php

declare(strict_types=1);

namespace Dakiquang\OAuthRefreshLock\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $key
 * @property string $access_token
 * @property string|null $refresh_token
 * @property CarbonImmutable|null $expires_at
 * @property array<array-key, string>|null $scopes
 * @property array<string, mixed>|null $meta
 * @property int $version
 * @property CarbonImmutable|null $revoked_at
 * @property string|null $revoked_reason
 */
class OAuthToken extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['access_token', 'refresh_token'];

    public function getTable(): string
    {
        $table = config('oauth-refresh-lock.table', 'oauth_refresh_tokens');

        return is_string($table) ? $table : 'oauth_refresh_tokens';
    }

    public function getConnectionName(): ?string
    {
        $connection = config('oauth-refresh-lock.connection');

        return is_string($connection) ? $connection : parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'immutable_datetime',
            'scopes' => 'array',
            'meta' => 'array',
            'version' => 'integer',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}
