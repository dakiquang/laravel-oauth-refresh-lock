<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\QuickBooks\QuickBooks;
use Carbon\CarbonImmutable;
use Dakiquang\OAuthRefreshLock\Contracts\TokenStore;
use Dakiquang\OAuthRefreshLock\Facades\OAuthRefreshLock;
use Dakiquang\OAuthRefreshLock\StoredToken;
use Illuminate\Console\Command;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Makes the stored access token look expired, then starts several processes
 * that all need a token at once. Expect exactly one "REFRESHED" line: the
 * others wait for the lock and use the token it stored.
 */
class QuickBooksRace extends Command
{
    protected $signature = 'quickbooks:race {realmId} {--workers=5}';

    protected $description = 'Show that concurrent workers refresh a QuickBooks token only once';

    public function handle(): int
    {
        $realmId = (string) $this->argument('realmId');
        $key = QuickBooks::key($realmId);

        // Keep the refresh token, move the access token's expiry into the past.
        OAuthRefreshLock::for($key)->withLock(function (TokenStore $store) use ($key): void {
            $current = $store->get($key) ?? throw new RuntimeException('Connect a company first: open /quickbooks/connect.');
            $store->put($key, new StoredToken($current->accessToken, $current->refreshToken, CarbonImmutable::now()->subMinute(), $current->scopes, $current->meta));
        });

        $workers = max(2, (int) $this->option('workers'));
        $results = Process::pool(function (Pool $pool) use ($workers, $realmId): void {
            for ($i = 0; $i < $workers; $i++) {
                $pool->path(base_path())->command([PHP_BINARY, 'artisan', 'quickbooks:company', $realmId]);
            }
        })->start()->wait();

        foreach ($results->collect() as $result) {
            $this->line(trim($result->output()) ?: trim($result->errorOutput()));
        }

        return self::SUCCESS;
    }
}
