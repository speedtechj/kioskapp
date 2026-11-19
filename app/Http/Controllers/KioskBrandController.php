<?php
namespace App\Http\Controllers;

use App\Models\{Business,Kiosk};
use App\Services\Access;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class KioskBrandController extends Controller
{
    public function show(Business $business, Kiosk $kiosk)
    {
        Access::authorize($business->id,'kiosks.manage',$kiosk->id);
        return view('branding.studio', ['business'=>$business,'kiosk'=>$kiosk,
            'kiosks'=>Access::kiosks(auth()->user(),$business->id),'tables'=>$kiosk->tables()->orderBy('name')->get()]);
    }

    public function save(Request $request, Business $business, Kiosk $kiosk)
    {
        Access::authorize($business->id,'kiosks.manage',$kiosk->id);
        $data=$request->validate(['app_label'=>'nullable|string|max:40',
            'icon'=>'nullable|file|mimes:png|max:3072|dimensions:ratio=1/1,min_width=192,max_width=2048']);
        $kiosk->app_label=trim($data['app_label'] ?? '') ?: null;
        if ($request->hasFile('icon')) {
            $source=imagecreatefrompng($request->file('icon')->getRealPath());
            abort_unless($source,422);
            $key=(string)Str::uuid();
            $dir="kiosk-icons/{$business->id}/{$kiosk->id}/{$key}";
            $disk=Storage::disk('public');
            try {
                foreach ([32,180,192,512] as $size) {
                    $out=imagecreatetruecolor($size,$size);
                    imagefill($out,0,0,imagecolorallocate($out,255,255,255));
                    imagecopyresampled($out,$source,0,0,0,0,$size,$size,imagesx($source),imagesy($source));
                    ob_start();
                    try { $encoded=imagepng($out); $bytes=ob_get_contents(); }
                    finally { ob_end_clean(); }
                    if (!$encoded || !$disk->put("$dir/$size.png",$bytes)) {
                        throw new \RuntimeException('Could not save app icons.');
                    }
                }
                $kiosk->app_icon_key=$key;
                $kiosk->save();
            } catch (\Throwable $e) {
                $disk->deleteDirectory($dir);
                throw $e;
            }
        } else {
            $kiosk->save();
        }
        return redirect()->route('kiosk.brand',[$business,$kiosk])->with('brand_saved',true);
    }
}
