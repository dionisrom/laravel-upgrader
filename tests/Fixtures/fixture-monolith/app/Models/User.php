<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @phpstan-ignore missingType.generics */
    use HasFactory, HasRoles, LogsActivity, Notifiable;

    /**
     * The attributes that should be mutated to dates.
     *
     * @var array
     */
    protected $dates = ['created_at', 'updated_at', 'email_verified_at', 'last_login_at'];

    /**
     * @var array<int, string>
     */
    protected $fillable = ['name', 'email', 'password', 'last_login_at'];

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

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email'])
            ->logOnlyDirty();
    }

    public function posts()
    {
        return $this->hasMany(Post::class);
    }
}
