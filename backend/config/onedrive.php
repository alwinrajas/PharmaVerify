<?php

return [
    /*
    |---------------------------------------------------------------------------
    | Driver
    |---------------------------------------------------------------------------
    | "graph" performs a real upload through the Microsoft Graph API.
    | "demo"  writes the file to local storage and simulates the upload so the
    |         complete business flow can be demonstrated before the client's
    |         Azure application registration is available.
    */
    'driver' => env('ONEDRIVE_DRIVER', 'demo'),

    'tenant_id' => env('ONEDRIVE_TENANT_ID'),
    'client_id' => env('ONEDRIVE_CLIENT_ID'),
    'client_secret' => env('ONEDRIVE_CLIENT_SECRET'),

    /*
    | Either a drive id, or the user principal name whose OneDrive should
    | receive the file. One of the two must be set when using the graph driver.
    */
    'drive_id' => env('ONEDRIVE_DRIVE_ID'),
    'user_principal' => env('ONEDRIVE_USER_PRINCIPAL'),

    'folder' => env('ONEDRIVE_FOLDER', 'PharmaVerify/FinalOutput'),

    /*
    | Demo driver only. A value between 0 and 1 that forces a proportion of
    | uploads to fail so the failure and retry states can be shown.
    */
    'demo_fail_rate' => (float) env('ONEDRIVE_DEMO_FAIL_RATE', 0),
];
