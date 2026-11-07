<?php

declare(strict_types=1);

namespace Dakiquang\OAuthRefreshLock\Refreshers;

use Closure;
use Dakiquang\OAuthRefreshLock\Contracts\Refresher;
use Dakiquang\OAuthRefreshLock\RefreshedToken;
use Dakiquang\OAuthRefreshLock\StoredToken;

final class CallbackRefresher implements Refresher
{
    /**
     * @param  Closure(StoredToken): RefreshedToken  $callback
     */
    public function __construct(private readonly Closure $callback) {}

    public function refresh(StoredToken $token): RefreshedToken
    {
        return ($this->callback)($token);
    }
}
