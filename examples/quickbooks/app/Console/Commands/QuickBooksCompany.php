<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\QuickBooks\QuickBooks;
use Dakiquang\OAuthRefreshLock\Events\TokenRefreshed;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Event;

/**
 * Calls the CompanyInfo API once and reports whether this process refreshed
 * the token. quickbooks:race runs several of these at the same time.
 */
class QuickBooksCompany extends Command
{
    protected $signature = 'quickbooks:company {realmId}';

    protected $description = 'Fetch the QuickBooks company name, refreshing the token if needed';

    public function handle(QuickBooks $quickBooks): int
    {
        $refreshed = false;
        Event::listen(TokenRefreshed::class, function () use (&$refreshed): void {
            $refreshed = true;
        });

        $company = $quickBooks->companyInfo((string) $this->argument('realmId'));

        $this->line(sprintf('pid %d: %s, company "%s"', getmypid(), $refreshed ? 'REFRESHED the token' : 'used the stored token', $company['CompanyName'] ?? '?'));

        return self::SUCCESS;
    }
}
