<?php

declare(strict_types=1);

// OAuth 2.0 web server flow: https://help.salesforce.com/s/articleView?id=sf.remoteaccess_oauth_web_server_flow.htm&type=5
return [
    'client_id' => env('SALESFORCE_CLIENT_ID'),
    'client_secret' => env('SALESFORCE_CLIENT_SECRET'),
    'redirect_uri' => env('SALESFORCE_REDIRECT_URI', 'http://localhost:8000/salesforce/callback'),
    // The org's My Domain URL; login.salesforce.com (test.salesforce.com for sandboxes) may answer
    // invalid_client_id for a newly created External Client App.
    'login_url' => env('SALESFORCE_LOGIN_URL', 'https://login.salesforce.com'),
    'api_version' => env('SALESFORCE_API_VERSION', 'v60.0'),
];
