<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UrlSlugs
{
    public static function scope(Model $model): int
    {
        return match ($model->getTable()) {
            'businesses' => 0,'kiosks' => (int) $model->business_id,'dining_tables' => (int) $model->kiosk_id
        };
    }

    public static function available(Model $model, string $slug): bool
    {
        $query = DB::table($model->getTable())->where('slug', $slug);
        if ($model->exists) {
            $query->where('id', '!=', $model->id);
        }
        if ($model->getTable() === 'kiosks') {
            $query->where('business_id', $model->business_id);
        }
        if ($model->getTable() === 'dining_tables') {
            $query->where('kiosk_id', $model->kiosk_id);
        }

        return ! $query->exists() && ! DB::table('url_slug_aliases')->where('resource_type', $model->getTable())->where('scope_id', self::scope($model))->where('slug', $slug)->where('resource_id', '!=', $model->id ?? 0)->exists();
    }

    public static function defaultSlug(Model $model): string
    {
        $base = substr(Str::slug($model->name ?? ''), 0, 90);
        if ($base === '' || ctype_digit($base)) {
            $base = match ($model->getTable()) {
                'businesses' => 'business','kiosks' => 'kiosk','dining_tables' => 'table'
            }.($base !== '' ? '-'.$base : '');
        }
        $slug = $base;
        $suffix = 2;
        while (! self::available($model, $slug)) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    public static function validate(Model $model, ?string $value, string $key = 'slug'): string
    {
        $slug = trim($value ?? '') === '' ? self::defaultSlug($model) : Str::slug($value);
        if (strlen($slug) > 120 || ! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) || ctype_digit($slug)) {
            throw ValidationException::withMessages([$key => 'Use a readable slug with letters, numbers and hyphens (maximum 120 characters).']);
        }
        if (! self::available($model, $slug)) {
            throw ValidationException::withMessages([$key => 'This slug is already used or reserved by an older link. Choose another slug.']);
        }

        return $slug;
    }
}
