<?php

declare(strict_types=1);

// Endpoints and scopes: https://developer.xero.com/documentation/guides/oauth2/auth-flow
return [
    'client_id' => env('XERO_CLIENT_ID'),
    'client_secret' => env('XERO_CLIENT_SECRET'),
    'redirect_uri' => env('XERO_REDIRECT_URI', 'http://localhost:8000/xero/callback'),
];
