<?php

namespace Modules\Reporting\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Reporting domain module service provider.
 *
 * Intentionally references the old-style \Illuminate\Contracts\Events\Dispatcher
 * binding — a migration target for L11 slim skeleton.
 */
class ReportingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('reporting.generator', function ($app) {
            return new \Modules\Reporting\Services\ReportGenerator(
                $app->make(\Illuminate\Contracts\Events\Dispatcher::class),
            );
        });
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'reporting');
    }
}
