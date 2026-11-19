<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegisterShift extends Model
{
    public $timestamps = false;

    protected $fillable = ['kiosk_id', 'opened_by', 'closed_by', 'opening_cash', 'opened_at', 'closed_at', 'expected_cash', 'counted_cash', 'denominations'];

    protected function casts(): array
    {
        return ['opened_at' => 'datetime', 'closed_at' => 'datetime', 'denominations' => 'array'];
    }
}
