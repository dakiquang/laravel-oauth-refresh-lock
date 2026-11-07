<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Google\Google;
use Dakiquang\OAuthRefreshLock\Events\TokenRefreshed;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Event;

/**
 * Calls the API once and reports whether this process refreshed the token.
 * google:race runs several of these at the same time.
 */
class GoogleCall extends Command
{
    protected $signature = 'google:call {subject}';

    protected $description = 'Fetch the signed-in Google account, refreshing the token if needed';

    public function handle(Google $google): int
    {
        $refreshed = false;
        Event::listen(TokenRefreshed::class, function () use (&$refreshed): void {
            $refreshed = true;
        });

        $result = $google->userinfo((string) $this->argument('subject'));

        $this->line(sprintf('pid %d: %s, account "%s"', getmypid(), $refreshed ? 'REFRESHED the token' : 'used the stored token', $result['email'] ?? '?'));

        return self::SUCCESS;
    }
}
