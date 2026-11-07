<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Salesforce\Salesforce;
use Dakiquang\OAuthRefreshLock\Contracts\TokenStore;
use Dakiquang\OAuthRefreshLock\Facades\OAuthRefreshLock;
use Dakiquang\OAuthRefreshLock\StoredToken;
use Illuminate\Console\Command;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Replaces the stored access token with one Salesforce will reject (as after a
 * session timeout), keeps the refresh token, and starts several processes that
 * call the API at once. Each gets 401 INVALID_SESSION_ID and reports the token
 * as rejected. Expect exactly one "REFRESHED" line.
 */
class SalesforceRace extends Command
{
    protected $signature = 'salesforce:race {orgId} {--workers=6}';

    protected $description = 'Show that concurrent workers refresh a rejected Salesforce token only once';

    public function handle(): int
    {
        $orgId = (string) $this->argument('orgId');
        $key = Salesforce::key($orgId);

        OAuthRefreshLock::for($key)->withLock(function (TokenStore $store) use ($key): void {
            $current = $store->get($key) ?? throw new RuntimeException('Connect an org first: open /salesforce/connect.');
            $store->put($key, new StoredToken('expired-'.bin2hex(random_bytes(8)), $current->refreshToken, null, $current->scopes, $current->meta));
        });

        $workers = max(2, (int) $this->option('workers'));
        $results = Process::pool(function (Pool $pool) use ($workers, $orgId): void {
            for ($i = 0; $i < $workers; $i++) {
                $pool->path(base_path())->command([PHP_BINARY, 'artisan', 'salesforce:organisation', $orgId]);
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
