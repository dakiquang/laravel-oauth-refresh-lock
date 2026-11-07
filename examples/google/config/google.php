<?php

declare(strict_types=1);

// https://developers.google.com/identity/protocols/oauth2/web-server
return [
    'client_id' => env('GOOGLE_CLIENT_ID'),
    'client_secret' => env('GOOGLE_CLIENT_SECRET'),
    'redirect_uri' => env('GOOGLE_REDIRECT_URI', 'http://localhost:8000/google/callback'),
];
