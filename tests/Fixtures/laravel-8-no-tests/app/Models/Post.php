<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    protected $dates = ['published_at', 'archived_at'];

    protected $fillable = ['title', 'body', 'published_at', 'archived_at'];
}
