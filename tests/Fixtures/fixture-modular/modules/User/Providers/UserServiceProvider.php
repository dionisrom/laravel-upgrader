<?php

namespace Modules\User\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\User\Models\User;

/**
 * Custom domain module service provider.
 *
 * Non-standard structure — migration target when the L11 slim skeleton tries
 * to eliminate the traditional provider array in config/app.php.
 */
class UserServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind('user.repository', function ($app) {
            return new \Modules\User\Repositories\UserRepository();
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'user');
    }
}
