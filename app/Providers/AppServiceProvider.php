<?php

namespace App\Providers;

use App\Queue\WorkCommand as AppWorkCommand;
use Illuminate\Pagination\Paginator;
use Illuminate\Queue\Console\WorkCommand as LaravelWorkCommand;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // ArtisanServiceProvider binds queue:work late (deferred). Re-bind just before resolve.
        $this->app->beforeResolving(LaravelWorkCommand::class, function () {
            static $bound = false;
            if ($bound) {
                return;
            }
            $bound = true;

            $this->app->singleton(LaravelWorkCommand::class, function ($app) {
                return new AppWorkCommand(
                    $app['queue.worker'],
                    $app['cache.store']
                );
            });
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::useBootstrapFive();

        $appUrl = (string) config('app.url');
        if (str_starts_with($appUrl, 'https://')) {
            URL::forceScheme('https');
        }

        if ($this->app->runningInConsole()) {
            $limit = (string) config('queue.worker.php_memory_limit', '1024M');
            if ($limit !== '') {
                @ini_set('memory_limit', $limit);
            }
        }
    }
}
