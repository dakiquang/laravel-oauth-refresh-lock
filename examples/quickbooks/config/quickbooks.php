<?php

declare(strict_types=1);

// Endpoints from Intuit's discovery document:
// https://developer.api.intuit.com/.well-known/openid_sandbox_configuration
return [
    'client_id' => env('QUICKBOOKS_CLIENT_ID'),
    'client_secret' => env('QUICKBOOKS_CLIENT_SECRET'),
    'redirect_uri' => env('QUICKBOOKS_REDIRECT_URI'),
    'authorize_url' => env('QUICKBOOKS_AUTHORIZE_URL', 'https://appcenter.intuit.com/connect/oauth2'),
    'token_url' => env('QUICKBOOKS_TOKEN_URL', 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer'),
    'userinfo_url' => env('QUICKBOOKS_USERINFO_URL', 'https://sandbox-accounts.platform.intuit.com/v1/openid_connect/userinfo'),
    'api_base' => env('QUICKBOOKS_API_BASE', 'https://sandbox-quickbooks.api.intuit.com'),
];
