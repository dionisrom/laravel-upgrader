<?php

namespace App\Providers;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Post::class => \App\Policies\PostPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();

        Gate::define('admin-access', function (User $user) {
            return $user->hasRole('admin');
        });

        Gate::define('moderator-access', function (User $user) {
            return $user->hasAnyRole(['admin', 'moderator']);
        });
    }
}
