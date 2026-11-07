<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Salesforce\Salesforce;
use Dakiquang\OAuthRefreshLock\Exceptions\TokenNotFound;
use Dakiquang\OAuthRefreshLock\Exceptions\TokenRevoked;
use Dakiquang\OAuthRefreshLock\Exceptions\TransientRefreshFailure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use League\OAuth2\Client\Token\AccessToken;

/**
 * A minimal connect / call / disconnect flow. The connected org is kept in the
 * session to keep the example short; a real app stores the org ID with its own
 * account.
 */
class SalesforceController extends Controller
{
    public function __construct(private readonly Salesforce $salesforce) {}

    public function connect(Request $request): RedirectResponse
    {
        $provider = $this->salesforce->provider();
        $url = $provider->getAuthorizationUrl();
        $request->session()->put('salesforce_state', $provider->getState());

        return redirect()->away($url);
    }

    public function callback(Request $request): RedirectResponse
    {
        $state = $request->session()->pull('salesforce_state');
        abort_unless(is_string($state) && hash_equals($state, (string) $request->query('state')), 403, 'Invalid OAuth state.');

        $token = $this->salesforce->provider()->getAccessToken('authorization_code', ['code' => (string) $request->query('code')]);
        abort_unless($token instanceof AccessToken, 500);

        // The org ID comes from Salesforce's own token response, not from the browser.
        $orgId = $this->salesforce->orgId($token);
        $this->salesforce->connect($orgId, $token);
        $request->session()->put('salesforce_org', $orgId);

        return redirect('/salesforce/organisation');
    }

    public function organisation(Request $request): JsonResponse|RedirectResponse
    {
        $orgId = $request->session()->get('salesforce_org');
        if (! is_string($orgId)) {
            return redirect('/salesforce/connect');
        }

        try {
            return response()->json($this->salesforce->query($orgId, 'SELECT Name FROM Organization'));
        } catch (TokenNotFound|TokenRevoked) {
            // Salesforce rejected the refresh token (invalid_grant): the user has to connect again.
            return redirect('/salesforce/connect');
        } catch (TransientRefreshFailure $failure) {
            return response()->json(['error' => 'Salesforce is unavailable, try again later.', 'code' => $failure->errorCode], 503);
        } catch (ConnectionException|RequestException) {
            return response()->json(['error' => 'The Salesforce API call failed.'], 502);
        }
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $orgId = $request->session()->pull('salesforce_org');
        if (is_string($orgId)) {
            $this->salesforce->disconnect($orgId);
        }

        return redirect('/');
    }
}
