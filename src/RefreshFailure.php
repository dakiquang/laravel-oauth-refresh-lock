<?php

declare(strict_types=1);

namespace Dakiquang\OAuthRefreshLock;

enum RefreshFailure: string
{
    /** The provider rejected the refresh token for good (`invalid_grant`). The user must reconnect. */
    case Revoked = 'revoked';

    /** Network error, timeout, rate limit or 5xx. Keep the token and try again later. */
    case Transient = 'transient';

    /** Anything else, such as a misconfigured client. Keep the token and let the error surface. */
    case Unexpected = 'unexpected';
}
