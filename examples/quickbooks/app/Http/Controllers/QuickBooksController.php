<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\QuickBooks\QuickBooks;
use Dakiquang\OAuthRefreshLock\Exceptions\TokenNotFound;
use Dakiquang\OAuthRefreshLock\Exceptions\TokenRevoked;
use Dakiquang\OAuthRefreshLock\Exceptions\TransientRefreshFailure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A minimal connect / call / disconnect flow. The connected company is kept in
 * the session to keep the example short; a real app stores the realm ID with
 * its own account.
 */
class QuickBooksController extends Controller
{
    public function __construct(private readonly QuickBooks $quickBooks) {}

    public function connect(Request $request): RedirectResponse
    {
        $provider = $this->quickBooks->provider();
        $url = $provider->getAuthorizationUrl();
        $request->session()->put('quickbooks_state', $provider->getState());

        return redirect()->away($url);
    }

    public function callback(Request $request): RedirectResponse
    {
        $state = $request->session()->pull('quickbooks_state');
        abort_unless(is_string($state) && hash_equals($state, (string) $request->query('state')), 403, 'Invalid OAuth state.');

        $realmId = (string) $request->query('realmId');
        $token = $this->quickBooks->provider()->getAccessToken('authorization_code', ['code' => (string) $request->query('code')]);
        try {
            abort_unless($this->quickBooks->tokenBelongsTo($realmId, $token), 400, 'The company does not match the authorisation.');
        } catch (ConnectionException|RequestException) {
            abort(502, 'QuickBooks did not answer; connect again in a moment.');
        }
        $this->quickBooks->connect($realmId, $token);
        $request->session()->put('quickbooks_realm', $realmId);

        return redirect('/quickbooks/company');
    }

    public function company(Request $request): JsonResponse|RedirectResponse
    {
        $realmId = $request->session()->get('quickbooks_realm');
        if (! is_string($realmId)) {
            return redirect('/quickbooks/connect');
        }

        try {
            return response()->json($this->quickBooks->companyInfo($realmId));
        } catch (TokenNotFound|TokenRevoked) {
            // Intuit rejected the refresh token: the user has to connect again.
            return redirect('/quickbooks/connect');
        } catch (TransientRefreshFailure $failure) {
            return response()->json(['error' => 'QuickBooks is unavailable, try again later.', 'code' => $failure->errorCode], 503);
        } catch (ConnectionException|RequestException) {
            return response()->json(['error' => 'The QuickBooks API call failed.'], 502);
        }
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $realmId = $request->session()->pull('quickbooks_realm');
        if (is_string($realmId)) {
            $this->quickBooks->disconnect($realmId);
        }

        return redirect('/');
    }
}
