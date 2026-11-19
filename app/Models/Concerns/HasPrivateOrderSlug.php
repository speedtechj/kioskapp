<?php

namespace App\Models\Concerns;

trait HasPrivateOrderSlug
{
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    protected static function bootHasPrivateOrderSlug(): void
    {
        static::creating(function ($order) {
            $order->slug = 'order-'.bin2hex(random_bytes(16));
        });
        static::updating(function ($order) {
            if ($order->isDirty('slug')) {
                throw new \LogicException('Private order slugs cannot be edited.');
            }
        });
    }
}
