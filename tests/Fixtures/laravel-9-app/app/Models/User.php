<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    protected $fillable = ['name', 'email'];

    /**
     * Missing return type — should be added by LaravelModelReturnTypeRector.
     */
    public function toArray()
    {
        return array_merge(parent::toArray(), [
            'full_name' => $this->first_name . ' ' . $this->last_name,
        ]);
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function (self $model): void {
            $model->uuid = \Illuminate\Support\Str::uuid()->toString();
        });
    }
}
