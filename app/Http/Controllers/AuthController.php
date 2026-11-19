<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Kiosk;
use App\Services\Access;
use App\Services\BusinessAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        $key = strtolower($data['email']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => 'Too many attempts. Please try again in a minute.']);
        }
        if (! Auth::attempt($data)) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['email' => 'The email or password is incorrect.']);
        }
        RateLimiter::clear($key);
        $request->session()->regenerate();
        BusinessAudit::session('Signed in', $request->user());

        return redirect()->route('home')->with('open_assigned_pos', true);
    }

    public function home(Request $request)
    {
        $user = $request->user();
        $businesses = $user->is_super_admin ? Business::all() : $user->businesses()->get();
        $workspaces = $businesses->map(fn (Business $business) => [
            'business' => $business,
            'kiosks' => Access::kiosks($user, $business->id)->filter(fn ($kiosk) => $kiosk->active)->values(),
            'dashboard' => Access::allows($user, $business->id, 'dashboard.view'),
            'pos' => Access::allows($user, $business->id, 'pos.use'),
            'kitchen' => Access::allows($user, $business->id, 'kitchen.use'),
        ]);
        $cashierOnly = ! $user->is_super_admin && $workspaces->isNotEmpty()
            && $workspaces->every(fn (array $workspace) => $workspace['business']->pivot->role === 'cashier'
                && $workspace['pos'] && ! $workspace['dashboard'] && ! $workspace['kitchen']);

        if ($request->session()->pull('open_assigned_pos') && $cashierOnly) {
            $kiosks = $workspaces->flatMap(fn (array $workspace) => $workspace['kiosks']);
            if ($kiosks->count() === 1) {
                $kiosk = $kiosks->first();

                return redirect()->route('pos', [$kiosk->business, $kiosk]);
            }
        }

        return view('home', compact('user', 'workspaces', 'cashierOnly'));
    }

    public function logout(Request $request)
    {
        $scope = $request->session()->get('pos_pin_scope');
        $kiosk = $scope ? Kiosk::where('business_id', $scope['business'])->where('active', true)->find($scope['kiosk']) : null;
        BusinessAudit::session('Signed out', $request->user(), $scope);
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect($kiosk ? CashierPwaController::startUrl($kiosk->business, $kiosk) : '/login');
    }
}
