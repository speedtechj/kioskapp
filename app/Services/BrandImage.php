<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class BrandImage
{
    public static function rules(int $min = 32, bool $square = false): array
    {
        return ['nullable', 'file', 'image', 'mimes:png,jpg,jpeg,webp', 'max:3072',
            "dimensions:min_width=$min,min_height=$min,max_width=2048,max_height=2048".($square ? ',ratio=1/1' : '')];
    }

    public static function store(UploadedFile $upload, string $folder): string
    {
        return $upload->store($folder, 'public') ?: throw new RuntimeException('Could not save logo.');
    }

    public static function kioskIcon(UploadedFile $upload, int $businessId, int $kioskId): string
    {
        $source = imagecreatefromstring(file_get_contents($upload->getRealPath()));
        if (! $source) {
            throw new RuntimeException('Could not read image.');
        }

        $key = (string) Str::uuid();
        $folder = "kiosk-icons/$businessId/$kioskId/$key";
        try {
            foreach ([32, 180, 192, 512] as $size) {
                $icon = imagescale($source, $size, $size);
                if (! $icon) {
                    throw new RuntimeException('Could not resize image.');
                }
                ob_start();
                try {
                    $ok = imagepng($icon);
                    $bytes = ob_get_contents();
                } finally {
                    ob_end_clean();
                }
                if (! $ok || ! Storage::disk('public')->put("$folder/$size.png", $bytes)) {
                    throw new RuntimeException('Could not save icon.');
                }
            }
        } catch (\Throwable $exception) {
            Storage::disk('public')->deleteDirectory($folder);
            throw $exception;
        }

        return $key;
    }
}
