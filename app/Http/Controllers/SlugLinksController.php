<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\DiningTable;
use App\Models\Kiosk;
use App\Models\Order;
use App\Services\Access;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;

class SlugLinksController extends Controller
{
    public function legacy(Request $request)
    {
        $p = $request->route()->parameters();
        $name = $request->route()->defaults['destination'];
        if (isset($p['businessId'])) {
            $b = Business::findOrFail($p['businessId']);
            abort_unless(auth()->user()->is_super_admin || Access::membership(auth()->user(), $b->id), 403);
            $args = [$b];
        }
        if (isset($p['kioskId'])) {
            $k = Kiosk::findOrFail($p['kioskId']);
            if (isset($b)) {
                abort_unless($k->business_id === $b->id, 404);
            } else {
                $b = $k->business;
            }Access::authorize($b->id, match ($name) {
                'pos' => 'pos.use','kitchen' => 'kitchen.use','payments' => 'pos.use',default => 'kiosks.manage'
            }, $k->id);
            $args = [$b, $k];
        }
        if (isset($p['orderId'])) {
            $o = Order::findOrFail($p['orderId']);
            abort_unless($o->kiosk_id === $k->id, 404);
            $args[] = $o;
        }
        if (isset($p['tableId'])) {
            $t = DiningTable::findOrFail($p['tableId']);
            Access::authorize($t->kiosk->business_id, 'kiosks.manage', $t->kiosk_id);
            $args = [$t->kiosk->business, $t->kiosk, $t];
        }
        if (isset($p['token'])) {
            if (str_starts_with($name, 'menu')) {
                $t = DiningTable::where('token', $p['token'])->where('active', true)->firstOrFail();
                abort_unless($t->kiosk->active, 404);
                $args = [$t->kiosk->business, $t->kiosk, $t];
            } else {
                $k = Kiosk::where('takeout_token', $p['token'])->where('active', true)->firstOrFail();
                $args = [$k->business, $k];
            }
        }

        return redirect()->route($name, array_merge($args, $request->query()), 301)->header('Cache-Control', 'private, no-store');
    }

    public function qr(Business $business, Kiosk $kiosk, DiningTable $table)
    {
        Access::authorize($business->id, 'kiosks.manage', $kiosk->id);

        return $this->svg(self::scanUrl('menu', [$business, $kiosk, $table]));
    }

    public function takeoutQr(Business $business, Kiosk $kiosk)
    {
        Access::authorize($business->id, 'kiosks.manage', $kiosk->id);

        return $this->svg(self::scanUrl('takeout', [$business, $kiosk]));
    }

    public static function scanUrl(string $route, array $parameters): string
    {
        return request()->getSchemeAndHttpHost().route($route, $parameters, false);
    }

    private function svg(string $url)
    {
        return response((new Writer(new ImageRenderer(new RendererStyle(320), new SvgImageBackEnd)))->writeString($url))->header('Content-Type', 'image/svg+xml')->header('Cache-Control', 'private, no-store');
    }

    public function menuManifest(Business $business, Kiosk $kiosk, DiningTable $table)
    {
        abort_unless($table->active && $kiosk->active, 404);

        return $this->manifest($kiosk, '/menu/'.$table->token, route('menu', [$business, $kiosk, $table], false), $business->name.' Ordering');
    }

    public function takeoutManifest(Business $business, Kiosk $kiosk)
    {
        abort_unless($kiosk->active, 404);

        return $this->manifest($kiosk, '/takeout/'.$kiosk->takeout_token, route('takeout', [$business, $kiosk], false), $business->name.' Takeout');
    }

    private function manifest(Kiosk $kiosk, string $id, string $start, string $name)
    {
        $data = json_decode(file_get_contents(public_path('manifest.webmanifest')), true);
        $data['id'] = $id;
        $data['start_url'] = $start;
        $data['name'] = $kiosk->appLabel();
        $data['short_name'] = $kiosk->appLabel();
        $data['icons'] = array_map(fn ($size) => ['src' => $kiosk->appIcon($size), 'sizes' => "{$size}x{$size}", 'type' => 'image/png', 'purpose' => 'any'], [192, 512]);

        return response()->json($data)->header('Content-Type', 'application/manifest+json');
    }
}
