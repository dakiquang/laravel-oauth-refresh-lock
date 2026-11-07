<?php

declare(strict_types=1);

namespace Dakiquang\OAuthRefreshLock\Contracts;

use Dakiquang\OAuthRefreshLock\RefreshFailure;
use Throwable;

interface ErrorClassifier
{
    public function classify(Throwable $error): RefreshFailure;
}
