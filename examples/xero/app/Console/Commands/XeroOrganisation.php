<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Xero\Xero;
use Dakiquang\OAuthRefreshLock\Events\TokenRefreshed;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Event;

/**
 * Calls the Organisation API once and reports whether this process refreshed
 * the token. xero:race runs several of these at the same time.
 */
class XeroOrganisation extends Command
{
    protected $signature = 'xero:organisation {tenantId}';

    protected $description = 'Fetch the Xero organisation name, refreshing the token if needed';

    public function handle(Xero $xero): int
    {
        $refreshed = false;
        Event::listen(TokenRefreshed::class, function () use (&$refreshed): void {
            $refreshed = true;
        });

        $organisation = $xero->organisation((string) $this->argument('tenantId'));

        $this->line(sprintf('pid %d: %s, organisation "%s"', getmypid(), $refreshed ? 'REFRESHED the token' : 'used the stored token', $organisation['Name'] ?? '?'));

        return self::SUCCESS;
    }
}
