<?php

namespace App\Providers;

use App\Services\OneDrive\DemoOneDriveUploader;
use App\Services\OneDrive\GraphOneDriveUploader;
use App\Services\OneDrive\OneDriveUploader;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The rest of the application only ever asks for "the OneDrive
        // uploader"; which implementation answers is a configuration choice.
        $this->app->bind(OneDriveUploader::class, function () {
            return config('onedrive.driver') === 'graph'
                ? new GraphOneDriveUploader
                : new DemoOneDriveUploader;
        });
    }

    public function boot(): void
    {
        // Keeps index key lengths inside the limits of every target engine.
        Schema::defaultStringLength(191);

        Model::preventLazyLoading(false);
        Model::unguard(false);

        $this->defineRateLimits();
    }

    /**
     * Rate limits for the API.
     *
     * Every limit is keyed to a caller — a signed-in user, or an address for
     * anyone not yet signed in — rather than counted globally, so one busy or
     * hostile caller cannot exhaust the allowance of everybody else.
     */
    private function defineRateLimits(): void
    {
        // General API traffic. A screen in the web application fires several
        // requests at once (list, options, summary), so the signed-in
        // allowance is deliberately roomy; it exists to stop runaway loops and
        // scripted abuse, not to pace normal use.
        //
        // The HHT devices sit on this limit too. A device sends one submission
        // per completed count and retries only when a connection drops, so the
        // allowance leaves ample room for genuine retries — which matters,
        // because a retry is how the idempotency guarantee is exercised.
        RateLimiter::for('api', function (Request $request) {
            return $request->user()
                ? Limit::perMinute(300)->by('api-user:'.$request->user()->id)
                : Limit::perMinute(60)->by('api-ip:'.$request->ip());
        });

        // Signing in. Two limits apply together:
        //
        //   • per email *and* address — so one person fumbling their password
        //     cannot lock anybody else out, and an attacker cannot lock a known
        //     account out from elsewhere;
        //   • per address alone — so a single origin cannot spray attempts
        //     across many accounts to sidestep the first limit.
        RateLimiter::for('login', function (Request $request) {
            $email = Str::lower(trim((string) $request->input('email')));

            return [
                Limit::perMinute(5)->by('login:'.sha1($email.'|'.$request->ip())),
                Limit::perMinute(20)->by('login-origin:'.$request->ip()),
            ];
        });

        // Stock import. One import reads a large workbook and rewrites a
        // shop's stock, so it is expensive and slow by nature. The allowance is
        // well above any genuine working pattern — an import takes over a
        // minute, so six in ten minutes cannot be reached by hand — while still
        // preventing a caller from starting them back to back.
        RateLimiter::for('stock-import', function (Request $request) {
            return Limit::perMinutes(10, 6)
                ->by('stock-import:'.($request->user()?->id ?? $request->ip()));
        });
    }
}
