<?php

declare(strict_types=1);

// v2.0 endpoints: https://learn.microsoft.com/en-us/entra/identity-platform/v2-oauth2-auth-code-flow
return [
    'client_id' => env('MICROSOFT_CLIENT_ID'),
    'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
    'tenant' => env('MICROSOFT_TENANT', 'common'),
    'redirect_uri' => env('MICROSOFT_REDIRECT_URI', 'http://localhost:8000/microsoft/callback'),
];
