<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Fixture model with custom route binding — should be flagged by RouteBindingAuditor.
 */
class Article extends Model
{
    protected $fillable = ['title', 'slug', 'content'];

    /**
     * Custom route model binding by slug.
     * RouteBindingAuditor should detect this for review.
     */
    public function resolveRouteBinding($value, $field = null): ?self
    {
        return $this->where($field ?? 'slug', $value)->firstOrFail();
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
