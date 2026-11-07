<?php

declare(strict_types=1);

namespace Dakiquang\OAuthRefreshLock\Contracts;

use Dakiquang\OAuthRefreshLock\Classification;
use Throwable;

interface ErrorClassifier
{
    /**
     * Decide what a refresh failure means. The returned code must not contain
     * secrets: it is stored and used in exception messages.
     */
    public function classify(Throwable $error): Classification;
}
