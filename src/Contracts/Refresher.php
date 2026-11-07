<?php

declare(strict_types=1);

namespace Dakiquang\OAuthRefreshLock\Contracts;

use Dakiquang\OAuthRefreshLock\RefreshedToken;
use Dakiquang\OAuthRefreshLock\StoredToken;

interface Refresher
{
    /**
     * Exchange the stored refresh token for a new token set.
     *
     * Throw whatever the HTTP client or SDK throws; an ErrorClassifier decides
     * whether the failure revokes the token or is temporary.
     */
    public function refresh(StoredToken $token): RefreshedToken;
}
