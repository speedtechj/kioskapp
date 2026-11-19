<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Kiosk;
use App\Services\StaffPins;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CashierPwaController extends Controller
{
    public static function startUrl(Business $business, Kiosk $kiosk): string
    {
        return URL::signedRoute('cashier.sign-in', [$business, $kiosk], absolute: false);
    }

    public function manifest(Business $business, Kiosk $kiosk)
    {
        abort_unless($kiosk->active, 404);

        return response()->json([
            'id' => '/gather-cashier-pos/'.$kiosk->id,
            'name' => $business->name.' Cashier POS · '.$kiosk->name,
            'short_name' => 'Gather POS',
            'start_url' => self::startUrl($business, $kiosk),
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => '#f4ede1',
            'theme_color' => '#282e1d',
            'icons' => array_map(fn ($size) => [
                'src' => $kiosk->cashierIcon($size), 'sizes' => $size.'x'.$size, 'type' => 'image/png',
            ], [192, 512]),
        ])->withHeaders(['Content-Type' => 'application/manifest+json', 'Cache-Control' => 'no-cache']);
    }

    public function save(Request $request, Business $business, Kiosk $kiosk)
    {
        abort_unless($kiosk->active && StaffPins::canManage($request->user(), $business, $kiosk), 403);
        $request->validate([
            'favicon' => 'nullable|required_without:icon|image|mimes:png|max:3072|dimensions:ratio=1/1,min_width=32,max_width=2048',
            'icon' => 'nullable|required_without:favicon|image|mimes:png|max:3072|dimensions:ratio=1/1,min_width=512,max_width=2048',
        ]);

        $disk = Storage::disk('public');
        $created = [];
        try {
            foreach (['favicon' => [32], 'icon' => [180, 192, 512]] as $field => $sizes) {
                if (! $request->hasFile($field)) {
                    continue;
                }
                $key = (string) Str::uuid();
                $directory = "cashier-icons/{$business->id}/{$kiosk->id}/$key";
                $created[] = $directory;
                $this->writeIcons($request->file($field), $field, $directory, $sizes);
                $kiosk->setAttribute('cashier_'.$field.'_key', $key);
            }
            $kiosk->saveOrFail();
        } catch (\Throwable $exception) {
            foreach ($created as $directory) {
                $disk->deleteDirectory($directory);
            }
            throw $exception;
        }

        return redirect()->route('cashier.access', [$business, $kiosk])->with('pin_status', 'Cashier POS branding saved.');
    }

    private function writeIcons(UploadedFile $file, string $field, string $directory, array $sizes): void
    {
        $source = @imagecreatefrompng($file->getRealPath());
        if (! $source) {
            throw ValidationException::withMessages([$field => 'Choose a valid square PNG.']);
        }
        $disk = Storage::disk('public');
        foreach ($sizes as $size) {
            $image = imagecreatetruecolor($size, $size);
            imagealphablending($image, false);
            imagesavealpha($image, true);
            imagecopyresampled($image, $source, 0, 0, 0, 0, $size, $size, imagesx($source), imagesy($source));
            ob_start();
            try {
                $encoded = imagepng($image);
                $contents = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            if (! $encoded || ! $disk->put("$directory/$size.png", $contents)) {
                throw ValidationException::withMessages([$field => 'The icon could not be saved. Please try again.']);
            }
        }
    }
}
