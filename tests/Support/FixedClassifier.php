<?php

declare(strict_types=1);

namespace Dakiquang\OAuthRefreshLock\Tests\Support;

use Dakiquang\OAuthRefreshLock\Classification;
use Dakiquang\OAuthRefreshLock\Contracts\ErrorClassifier;
use Dakiquang\OAuthRefreshLock\RefreshFailure;
use Throwable;

final class FixedClassifier implements ErrorClassifier
{
    public function __construct(
        private readonly RefreshFailure $failure = RefreshFailure::Unexpected,
        private readonly string $code = 'test_code',
    ) {}

    public function classify(Throwable $error): Classification
    {
        return new Classification($this->failure, $this->code);
    }
}
