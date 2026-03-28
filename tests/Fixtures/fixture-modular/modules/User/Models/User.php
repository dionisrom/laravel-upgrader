<?php

namespace Modules\User\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * User model in non-standard module location.
 *
 * Note: models outside app/Models/ test that Rector handles PSR-4 paths beyond
 * the conventional directory.
 */
class User extends Authenticatable
{
    /** @phpstan-ignore missingType.generics */
    use HasFactory, Notifiable;

    /**
     * The attributes that should be mutated to dates.
     *
     * @var array
     */
    protected $dates = ['created_at', 'updated_at', 'email_verified_at'];

    /**
     * @var array<int, string>
     */
    protected $fillable = ['name', 'email', 'password'];

    /**
     * @var array<int, string>
     */
    protected $hidden = ['password', 'remember_token'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];
}
