<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Salesforce\Salesforce;
use Dakiquang\OAuthRefreshLock\Events\TokenRefreshed;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Event;

/**
 * Queries the org name once and reports whether this process refreshed the
 * token after a 401. salesforce:race runs several of these at the same time.
 */
class SalesforceOrganisation extends Command
{
    protected $signature = 'salesforce:organisation {orgId}';

    protected $description = 'Query the Salesforce org name; on 401 report the token as rejected';

    public function handle(Salesforce $salesforce): int
    {
        $refreshed = false;
        Event::listen(TokenRefreshed::class, function () use (&$refreshed): void {
            $refreshed = true;
        });

        $result = $salesforce->query((string) $this->argument('orgId'), 'SELECT Name FROM Organization');

        $this->line(sprintf('pid %d: %s, organisation "%s"', getmypid(), $refreshed ? 'REFRESHED the token' : 'used the stored token', $result['records'][0]['Name'] ?? '?'));

        return self::SUCCESS;
    }
}
