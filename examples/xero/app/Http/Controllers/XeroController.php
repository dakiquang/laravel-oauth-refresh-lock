<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Xero\Xero;
use Dakiquang\OAuthRefreshLock\Exceptions\TokenNotFound;
use Dakiquang\OAuthRefreshLock\Exceptions\TokenRevoked;
use Dakiquang\OAuthRefreshLock\Exceptions\TransientRefreshFailure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A minimal connect / call / disconnect flow. The connected organisation is
 * kept in the session to keep the example short; a real app stores the tenant
 * ID with its own account.
 */
class XeroController extends Controller
{
    public function __construct(private readonly Xero $xero) {}

    public function connect(Request $request): RedirectResponse
    {
        $provider = $this->xero->provider();
        $url = $provider->getAuthorizationUrl();
        $request->session()->put('xero_state', $provider->getState());

        return redirect()->away($url);
    }

    public function callback(Request $request): RedirectResponse
    {
        $state = $request->session()->pull('xero_state');
        abort_unless(is_string($state) && hash_equals($state, (string) $request->query('state')), 403, 'Invalid OAuth state.');

        $token = $this->xero->provider()->getAccessToken('authorization_code', ['code' => (string) $request->query('code')]);

        // The tenant comes from Xero's connections endpoint, called with the new token.
        $tenants = $this->xero->tenants($token);
        abort_if($tenants === [], 400, 'No Xero organisation was connected.');
        $tenantId = $tenants[0]['tenantId']; // the example connects one organisation

        $this->xero->connect($tenantId, $token);
        $request->session()->put('xero_tenant', $tenantId);

        return redirect('/xero/organisation');
    }

    public function organisation(Request $request): JsonResponse|RedirectResponse
    {
        $tenantId = $request->session()->get('xero_tenant');
        if (! is_string($tenantId)) {
            return redirect('/xero/connect');
        }

        try {
            return response()->json($this->xero->organisation($tenantId));
        } catch (TokenNotFound|TokenRevoked) {
            // Xero rejected the refresh token (invalid_grant): the user has to connect again.
            return redirect('/xero/connect');
        } catch (TransientRefreshFailure $failure) {
            return response()->json(['error' => 'Xero is unavailable, try again later.', 'code' => $failure->errorCode], 503);
        } catch (ConnectionException|RequestException) {
            return response()->json(['error' => 'The Xero API call failed.'], 502);
        }
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $tenantId = $request->session()->pull('xero_tenant');
        if (is_string($tenantId)) {
            $this->xero->disconnect($tenantId);
        }

        return redirect('/');
    }
}
