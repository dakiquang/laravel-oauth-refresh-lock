<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Google\Google;
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
 * that all need a token at once. Expect exactly one "REFRESHED" line.
 */
class GoogleRace extends Command
{
    protected $signature = 'google:race {subject} {--workers=6}';

    protected $description = 'Show that concurrent workers refresh a Google token only once';

    public function handle(): int
    {
        $subject = (string) $this->argument('subject');
        $key = Google::key($subject);

        // Keep the refresh token, move the access token's expiry into the past.
        OAuthRefreshLock::for($key)->withLock(function (TokenStore $store) use ($key): void {
            $current = $store->get($key) ?? throw new RuntimeException('Connect first: open /google/connect.');
            $store->put($key, new StoredToken($current->accessToken, $current->refreshToken, CarbonImmutable::now()->subMinute(), $current->scopes, $current->meta));
        });

        $workers = max(2, (int) $this->option('workers'));
        $results = Process::pool(function (Pool $pool) use ($workers, $subject): void {
            for ($i = 0; $i < $workers; $i++) {
                $pool->path(base_path())->command([PHP_BINARY, 'artisan', 'google:call', $subject]);
            }
        })->start()->wait();

        $refreshes = 0;
        foreach ($results->collect() as $result) {
            $this->line(trim($result->output()) ?: trim($result->errorOutput()));
            $refreshes += substr_count($result->output(), 'REFRESHED');
        }

        $allSucceeded = $results->collect()->every(fn ($result): bool => $result->successful());
        if (! $allSucceeded || $refreshes !== 1) {
            $this->error("Expected every process to succeed and exactly one refresh; got {$refreshes} refreshes.");

            return self::FAILURE;
        }

        $this->info("{$workers} processes, 1 refresh.");

        return self::SUCCESS;
    }
}
