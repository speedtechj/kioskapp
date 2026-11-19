<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BusinessAiSetting extends Model
{
    protected $primaryKey = 'business_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['business_id', 'enabled', 'master_configured', 'monthly_limit_cents', 'pause_at_limit', 'credentials', 'helpers'];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array', 'helpers' => 'array', 'enabled' => 'boolean', 'pause_at_limit' => 'boolean'];
    }
}
