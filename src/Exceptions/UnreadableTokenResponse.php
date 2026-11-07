<?php

declare(strict_types=1);

namespace Dakiquang\OAuthRefreshLock\Exceptions;

use Throwable;

/**
 * The token endpoint answered with something that is not an OAuth token
 * response, typically an HTML error page from a gateway during an outage.
 * Some SDKs drop the HTTP status in that case. The default classifier treats
 * it as temporary, so the token is kept. The SDK's error is getPrevious().
 */
final class UnreadableTokenResponse extends OAuthRefreshLockException
{
    public function __construct(Throwable $previous)
    {
        parent::__construct('The token endpoint returned a response that is not an OAuth token response.', 0, $previous);
    }
}
