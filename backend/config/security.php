<?php

return [

    /*
    |---------------------------------------------------------------------------
    | HTTP Strict Transport Security
    |---------------------------------------------------------------------------
    | Sent only over HTTPS — the header is meaningless on a plain connection and
    | browsers ignore it there.
    |
    | Behind a TLS-terminating proxy (IIS, Nginx) the application only knows the
    | original request was secure if the proxy is trusted, so set TRUSTED_PROXIES
    | in that deployment or this header will never be sent. See
    | 12-DEPLOYMENT-GUIDE.md §5.
    */

    'hsts' => [
        'enabled' => (bool) env('SECURITY_HSTS_ENABLED', true),

        // One year. Browsers ignore very short lifetimes for preload purposes,
        // and a long window is the point of the header.
        'max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31536000),

        'include_subdomains' => (bool) env('SECURITY_HSTS_INCLUDE_SUBDOMAINS', true),
    ],

    /*
    |---------------------------------------------------------------------------
    | Access token lifetime
    |---------------------------------------------------------------------------
    | *** PENDING OPERATIONAL / CLIENT DECISION — see 15-ASSUMPTIONS-DEPENDENCIES.md D-09 ***
    |
    | Both values are null by default, which means a token never expires. That is
    | the behaviour PharmaVerify has always had, so leaving these unset changes
    | nothing.
    |
    | They are separated because the two callers are not alike:
    |
    |   web    — a browser session. A shorter lifetime is cheap here: the user
    |            signs in again and carries on.
    |
    |   device — a handheld terminal. Expiry means someone must sign the device
    |            in again, and a device that expires mid-count during a stock
    |            take is a genuine operational problem. This window must be
    |            agreed with whoever runs the counts, not guessed at.
    |
    | Set either to a number of minutes to enable expiry for that caller. The
    | trade-off is stated plainly: a token that never expires stays valid on a
    | lost or stolen device until an administrator revokes it.
    */

    'tokens' => [
        'web_expiry_minutes' => env('AUTH_TOKEN_WEB_EXPIRY_MINUTES'),

        'device_expiry_minutes' => env('AUTH_TOKEN_DEVICE_EXPIRY_MINUTES'),
    ],

];
