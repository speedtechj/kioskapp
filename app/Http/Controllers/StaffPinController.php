<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Kiosk;
use App\Services\Access;
use App\Services\BusinessAudit;
use App\Services\StaffPins;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class StaffPinController extends Controller
{
    public function signIn(Request $request, Business $business, Kiosk $kiosk)
    {
        abort_unless($kiosk->active, 404);
        if ($request->user()) {
            $user = $request->user();
            if (Access::allows($user, $business->id, 'pos.use') && Access::kiosks($user, $business->id)->contains('id', $kiosk->id)) {
                BusinessAudit::record($business->id, 'Signed in with PIN', kioskId: $kiosk->id);

        return redirect()->route('pos', [$business, $kiosk]);
            }

            abort(403, 'Sign out before using this branch’s cashier link.');
        }
        $cashiers = StaffPins::staff($business, $kiosk, ['cashier'], true);

        return response()->view('staff-pin.sign-in', compact('business', 'kiosk', 'cashiers'))->header('Cache-Control', 'private, no-store');
    }

    public function authenticate(Request $request, Business $business, Kiosk $kiosk)
    {
        abort_unless($kiosk->active, 404);
        $data = $request->validate(['cashier' => 'required|integer', 'pin' => ['required', StaffPins::RULE]]);
        $user = StaffPins::staff($business, $kiosk, ['cashier'], true)->firstWhere('id', (int) $data['cashier']);
        if (! $user) {
            throw ValidationException::withMessages(['pin' => 'Select an assigned cashier and enter their PIN.']);
        }
        $hash = StaffPins::verify($business->id, $user->id, $data['pin']);
        Auth::login($user, false);
        $request->session()->regenerate();
        $request->session()->put('pos_pin_scope', ['business' => $business->id, 'kiosk' => $kiosk->id, 'version' => hash('sha256', $hash)]);

        return redirect()->route('pos', [$business, $kiosk]);
    }

    public function manage(Request $request, Business $business, Kiosk $kiosk)
    {
        abort_unless(StaffPins::canManage($request->user(), $business, $kiosk), 403);
        $url = CashierPwaController::startUrl($business, $kiosk);
        $link = $request->getSchemeAndHttpHost().$url;
        $qr = (new Writer(new ImageRenderer(new RendererStyle(320, 4), new SvgImageBackEnd)))->writeString($link);
        if ($request->query('download') === 'qr') {
            return response($qr)->withHeaders(['Content-Type' => 'image/svg+xml', 'Content-Disposition' => 'attachment; filename="cashier-pos-qr.svg"', 'Cache-Control' => 'private, no-store']);
        }
        $branches = Access::kiosks($request->user(), $business->id)->where('active', true)->sortBy('name');
        $staff = StaffPins::staff($business, $kiosk, ['cashier', 'owner', 'admin', 'manager']);
        $role = Access::membership($request->user(), $business->id)->pivot->role;

        return response()->view('staff-pin.manage', compact('business', 'kiosk', 'link', 'qr', 'staff', 'role', 'branches'))->header('Cache-Control', 'private, no-store');
    }

    public function resetPin(Request $request, Business $business, Kiosk $kiosk)
    {
        abort_unless(StaffPins::canManage($request->user(), $business, $kiosk), 403);
        $data = $request->validate(['staff' => 'required|integer', 'pin' => ['required', 'confirmed', StaffPins::RULE]]);
        $staff = StaffPins::staff($business, $kiosk, ['cashier', 'owner', 'admin', 'manager'])->firstWhere('id', (int) $data['staff']);
        $role = Access::membership($request->user(), $business->id)->pivot->role;
        abort_unless($staff && ($role === 'owner' || $staff->pivot->role === 'cashier'), 403);
        StaffPins::save($business->id, $staff->id, $data['pin']);
        BusinessAudit::record($business->id, 'Staff PIN reset', $staff->name, $kiosk->id);

        return redirect()->route('cashier.access', [$business, $kiosk])->with('pin_status', 'PIN saved.');
    }

    public function edit(Request $request, Business $business, Kiosk $kiosk)
    {
        $this->authorizeChange($request, $business, $kiosk);

        return response()->view('staff-pin.change', compact('business', 'kiosk'))->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, Business $business, Kiosk $kiosk)
    {
        $this->authorizeChange($request, $business, $kiosk);
        $data = $request->validate(['current_pin' => ['required', StaffPins::RULE], 'pin' => ['required', 'confirmed', 'different:current_pin', StaffPins::RULE]]);
        $userId = $request->user()->id;
        $oldHash = StaffPins::verify($business->id, $userId, $data['current_pin'], 'current_pin');
        $hash = StaffPins::save($business->id, $userId, $data['pin'], $oldHash);
        if ($request->session()->has('pos_pin_scope')) {
            $request->session()->put('pos_pin_scope.version', hash('sha256', $hash));
            $request->session()->regenerate();
        }

        BusinessAudit::record($business->id, 'Staff PIN changed', kioskId: $kiosk->id);

        return redirect()->route('pos', [$business, $kiosk])->with('pin_status', 'Your PIN was updated.');
    }

    private function authorizeChange(Request $request, Business $business, Kiosk $kiosk): void
    {
        Access::authorize($business->id, 'pos.use', $kiosk->id);
        abort_unless(Access::membership($request->user(), $business->id)?->pivot->role === 'cashier'
            && StaffPins::hashFor($business->id, $request->user()->id), 403);
    }
}
