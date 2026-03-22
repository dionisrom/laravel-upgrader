<?php

use Illuminate\Database\Eloquent\Model;

// Model has $guarded = [] set on the class itself — no unguard() calls
class Post extends Model
{
    protected $guarded = [];
}
