<?php

declare(strict_types=1);

namespace Dakiquang\OAuthRefreshLock\Tests\Support;

use Dakiquang\OAuthRefreshLock\Contracts\ErrorClassifier;
use Dakiquang\OAuthRefreshLock\RefreshFailure;
use Throwable;

final class FixedClassifier implements ErrorClassifier
{
    public function __construct(private readonly RefreshFailure $failure = RefreshFailure::Unexpected) {}

    public function classify(Throwable $error): RefreshFailure
    {
        return $this->failure;
    }
}
