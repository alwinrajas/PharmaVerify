<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds the response headers a browser needs to defend the application.
 *
 * Only headers that are correct for what this application actually serves are
 * set. A JSON API and a spreadsheet download want different things from a
 * markup page, and a header applied where it does not belong is either noise or
 * a broken download.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Safe on every response, including file downloads. It matters most on
        // the downloads: it stops a browser second-guessing the content type of
        // a generated workbook and treating it as something executable.
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Nothing here is meant to be framed. Kept alongside the CSP below
        // because older browsers honour this and not frame-ancestors.
        $response->headers->set('X-Frame-Options', 'DENY');

        // Referrer headers can carry ids in a path to a third party. None of
        // them are any use to anyone outside this application.
        $response->headers->set('Referrer-Policy', 'no-referrer');

        if ($this->shouldSendHsts($request)) {
            $response->headers->set('Strict-Transport-Security', $this->hstsValue());
        }

        if ($request->is('api/*')) {
            // The API returns JSON and files, never markup. Refusing every
            // source outright costs nothing and means a response that somehow
            // did contain markup could not load or send anything.
            $response->headers->set(
                'Content-Security-Policy',
                "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'"
            );
        }

        return $response;
    }

    /**
     * HSTS is only meaningful over HTTPS; sending it on a plain connection
     * achieves nothing and misrepresents how the response was served.
     *
     * Behind a TLS-terminating proxy, `isSecure()` is only true once that proxy
     * is trusted — see `TRUSTED_PROXIES` in the deployment guide.
     */
    private function shouldSendHsts(Request $request): bool
    {
        return $request->isSecure() && (bool) config('security.hsts.enabled', true);
    }

    private function hstsValue(): string
    {
        $value = 'max-age='.(int) config('security.hsts.max_age', 31536000);

        if (config('security.hsts.include_subdomains', true)) {
            $value .= '; includeSubDomains';
        }

        return $value;
    }
}
