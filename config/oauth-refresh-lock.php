<?php

declare(strict_types=1);

return [

    /*
    | Cache store used for the refresh lock. Must support atomic locks.
    | Use a shared store (redis, memcached, dynamodb, database) when more
    | than one server runs workers. null uses the default cache store.
    */
    'lock_store' => env('OAUTH_REFRESH_LOCK_STORE'),

    /*
    | Prefix for lock names. One lock is created per token key.
    */
    'lock_prefix' => 'oauth-refresh-lock:',

    /*
    | Refresh when the access token expires within this many seconds.
    */
    'skew_seconds' => 120,

    /*
    | How long the lock is held at most. Keep it longer than the HTTP timeout
    | of your token refresh call, so the lock cannot expire mid-refresh.
    */
    'lock_seconds' => 30,

    /*
    | How long a worker waits for another worker's refresh to finish.
    */
    'wait_seconds' => 10,

];
