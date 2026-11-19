<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class PlatformBranding extends Model
{
    protected $table = 'platform_branding';

    public $timestamps = false;

    public $incrementing = false;

    protected $fillable = ['name', 'logo', 'favicon'];

    public static function current(): self
    {
        $load = fn () => (Schema::hasTable('platform_branding') ? self::find(1) : null)
            ?? new self(['name' => 'Gather / Platform']);

        if (! app()->bound('gather.platform-branding')) {
            app()->scoped('gather.platform-branding', $load);
        }

        return app('gather.platform-branding');
    }

    public function imageUrl(string $field): string
    {
        return $this->$field ? asset('storage/'.$this->$field) : asset('branding/bowl-'.($field === 'favicon' ? '32' : '192').'.png');
    }
}
