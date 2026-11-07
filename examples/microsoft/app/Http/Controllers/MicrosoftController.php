<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Microsoft\Microsoft;
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
 * A minimal connect / call / disconnect flow. The connected user is kept in the
 * session to keep the example short; a real app stores the user object ID with its
 * own user.
 */
class MicrosoftController extends Controller
{
    public function __construct(private readonly Microsoft $microsoft) {}

    public function connect(Request $request): RedirectResponse
    {
        $provider = $this->microsoft->provider();
        $url = $provider->getAuthorizationUrl();
        $request->session()->put('microsoft_state', $provider->getState());

        return redirect()->away($url);
    }

    public function callback(Request $request): RedirectResponse
    {
        $state = $request->session()->pull('microsoft_state');
        abort_unless(is_string($state) && hash_equals($state, (string) $request->query('state')), 403, 'Invalid OAuth state.');

        // The user denied access, or the provider reported another error: there is no code to exchange.
        abort_if($request->query('error') !== null, 400, 'The authorisation was not granted.');
        abort_unless(is_string($request->query('code')) && $request->query('code') !== '', 400, 'No authorisation code in the callback.');

        $token = $this->microsoft->provider()->getAccessToken('authorization_code', ['code' => (string) $request->query('code')]);
        abort_unless($token instanceof AccessToken, 500);

        // The user object ID comes from Microsoft's API, called with the new token.
        $userObjectId = $this->microsoft->userObjectId($token);
        $this->microsoft->connect($userObjectId, $token);
        $request->session()->put('microsoft_id', $userObjectId);

        return redirect('/microsoft/me');
    }

    public function me(Request $request): JsonResponse|RedirectResponse
    {
        $userObjectId = $request->session()->get('microsoft_id');
        if (! is_string($userObjectId)) {
            return redirect('/microsoft/connect');
        }

        try {
            return response()->json($this->microsoft->me($userObjectId));
        } catch (TokenNotFound|TokenRevoked) {
            // Microsoft rejected the refresh token (invalid_grant): the user has to connect again.
            return redirect('/microsoft/connect');
        } catch (TransientRefreshFailure $failure) {
            return response()->json(['error' => 'Microsoft is unavailable, try again later.', 'code' => $failure->errorCode], 503);
        } catch (ConnectionException|RequestException) {
            return response()->json(['error' => 'The Microsoft API call failed.'], 502);
        }
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $userObjectId = $request->session()->pull('microsoft_id');
        if (is_string($userObjectId)) {
            $this->microsoft->disconnect($userObjectId);
        }

        return redirect('/');
    }
}
