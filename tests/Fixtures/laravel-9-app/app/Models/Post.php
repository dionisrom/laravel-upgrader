<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    protected $fillable = ['title', 'body', 'user_id'];

    /**
     * Uses deprecated $dates property — should be migrated to $casts.
     */
    protected $dates = ['published_at', 'archived_at'];

    public function toArray()
    {
        return array_merge(parent::toArray(), [
            'excerpt' => substr($this->body, 0, 100),
        ]);
    }

    public function jsonSerialize()
    {
        return $this->toArray();
    }
}
