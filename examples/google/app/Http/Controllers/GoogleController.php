<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Google\Google;
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
 * A minimal connect / call / disconnect flow. The connected account is kept in the
 * session to keep the example short; a real app stores the account ID (sub) with its
 * own user.
 */
class GoogleController extends Controller
{
    public function __construct(private readonly Google $google) {}

    public function connect(Request $request): RedirectResponse
    {
        [$url, $state] = $this->google->authorization();
        $request->session()->put('google_state', $state);

        return redirect()->away($url);
    }

    public function callback(Request $request): RedirectResponse
    {
        $state = $request->session()->pull('google_state');
        abort_unless(is_string($state) && hash_equals($state, (string) $request->query('state')), 403, 'Invalid OAuth state.');

        $token = $this->google->provider()->getAccessToken('authorization_code', ['code' => (string) $request->query('code')]);
        abort_unless($token instanceof AccessToken, 500);

        // The account ID (sub) comes from Google's API, called with the new token.
        $subject = $this->google->subject($token);
        $this->google->connect($subject, $token);
        $request->session()->put('google_id', $subject);

        return redirect('/google/userinfo');
    }

    public function userinfo(Request $request): JsonResponse|RedirectResponse
    {
        $subject = $request->session()->get('google_id');
        if (! is_string($subject)) {
            return redirect('/google/connect');
        }

        try {
            return response()->json($this->google->userinfo($subject));
        } catch (TokenNotFound|TokenRevoked) {
            // Google rejected the refresh token (invalid_grant): the user has to connect again.
            return redirect('/google/connect');
        } catch (TransientRefreshFailure $failure) {
            return response()->json(['error' => 'Google is unavailable, try again later.', 'code' => $failure->errorCode], 503);
        } catch (ConnectionException|RequestException) {
            return response()->json(['error' => 'The Google API call failed.'], 502);
        }
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $subject = $request->session()->pull('google_id');
        if (is_string($subject)) {
            $this->google->disconnect($subject);
        }

        return redirect('/');
    }
}
