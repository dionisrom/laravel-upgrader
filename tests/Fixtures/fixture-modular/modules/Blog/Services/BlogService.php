<?php

namespace Modules\Blog\Services;

final class BlogService
{
    public function slugify(string $title): string
    {
        return strtolower(str_replace(' ', '-', trim($title)));
    }
}