<?php

namespace App\Models\Concerns;

use App\Services\UrlSlugs;
use Illuminate\Support\Facades\DB;

trait HasReadableSlug
{
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    protected static function bootHasReadableSlug(): void
    {
        static::saving(function ($model) {
            if (! $model->exists || $model->isDirty('slug')) {
                $model->slug = UrlSlugs::validate($model, $model->slug);
            }
        });
        static::updated(function ($model) {
            $old = $model->getRawOriginal('slug');
            if ($old && $old !== $model->slug) {
                DB::table('url_slug_aliases')->updateOrInsert(['resource_type' => $model->getTable(), 'scope_id' => UrlSlugs::scope($model), 'slug' => $old], ['resource_id' => $model->id]);
            }
        });
    }

    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        if ($field && $field !== 'slug') {
            return parent::resolveRouteBindingQuery($query, $value, $field);
        }
        $table = $this->getTable();

        return $query->where(function ($where) use ($table, $value) {
            $where->where($table.'.slug', $value)->orWhereIn($table.'.id', DB::table('url_slug_aliases')->select('resource_id')->where('resource_type', $table)->where('slug', $value));
        });
    }
}
