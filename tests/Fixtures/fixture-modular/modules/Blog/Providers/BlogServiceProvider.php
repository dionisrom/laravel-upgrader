<?php

namespace Modules\Blog\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Blog domain module service provider.
 *
 * Registers blog-specific bindings, routes, and migrations from a module directory.
 */
class BlogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('blog.service', function ($app) {
            return new \Modules\Blog\Services\BlogService();
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'blog');
        $this->loadRoutesFrom(__DIR__ . '/../routes/blog.php');
    }
}
