<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Microsoft\Microsoft;
use Dakiquang\OAuthRefreshLock\Events\TokenRefreshed;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Event;

/**
 * Calls the API once and reports whether this process refreshed the token.
 * microsoft:race runs several of these at the same time.
 */
class MicrosoftCall extends Command
{
    protected $signature = 'microsoft:call {userObjectId}';

    protected $description = 'Fetch the signed-in user from Microsoft Graph, refreshing the token if needed';

    public function handle(Microsoft $microsoft): int
    {
        $refreshed = false;
        Event::listen(TokenRefreshed::class, function () use (&$refreshed): void {
            $refreshed = true;
        });

        $result = $microsoft->me((string) $this->argument('userObjectId'));

        $this->line(sprintf('pid %d: %s, user "%s"', getmypid(), $refreshed ? 'REFRESHED the token' : 'used the stored token', $result['displayName'] ?? '?'));

        return self::SUCCESS;
    }
}
