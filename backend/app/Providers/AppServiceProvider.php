<?php

namespace App\Providers;

use App\Services\OneDrive\DemoOneDriveUploader;
use App\Services\OneDrive\GraphOneDriveUploader;
use App\Services\OneDrive\OneDriveUploader;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

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
    }
}
