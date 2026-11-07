<?php

declare(strict_types=1);

namespace Dakiquang\OAuthRefreshLock\Exceptions;

use Throwable;

final class RefreshLockTimeout extends OAuthRefreshLockException
{
    public function __construct(
        public readonly string $key,
        public readonly int $waitedSeconds,
        ?Throwable $previous = null,
    ) {
        parent::__construct("Waited {$waitedSeconds}s for another process to refresh the OAuth token for key [{$key}]. Nothing was changed.", 0, $previous);
    }
}
