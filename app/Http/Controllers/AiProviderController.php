<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\BusinessAiSetting;
use App\Services\BusinessAudit;
use App\Services\GatherAi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AiProviderController extends Controller
{
    public function __invoke(Request $request, Business $business, string $provider, GatherAi $ai)
    {
        abort_unless(GatherAi::canManage($request->user(), $business->id), 403);
        abort_unless(isset(config('gather-ai.providers')[$provider]), 404);

        $settings = GatherAi::settings($business->id);
        $action = $request->input('action');
        $key = $request->input('api_key');
        if (($action === 'save' || ($action === 'test' && $key !== null)) && (! is_string($key) || ! preg_match('/\A[!-~]{12,512}\z/', $key))) {
            return $this->back($business, $provider, 'Enter a valid API key.', false);
        }

        if ($action === 'test') {
            $testKey = $key ?? ($settings->credentials[$provider] ?? null);
            $ok = $testKey && $ai->test($provider, $testKey);

            return $this->back($business, $provider, $ok ? 'Connection verified.' : 'Connection failed. Check the key and model access.', $ok);
        }
        abort_unless(in_array($action, ['save', 'remove'], true), 422);

        if ($action === 'save' && ! $ai->test($provider, $key)) {
            return $this->back($business, $provider, 'Key could not be verified. Check the key and model access, then try again.', false);
        }

        DB::transaction(function () use ($business, $provider, $action, $key) {
            $settings = BusinessAiSetting::lockForUpdate()->findOrFail($business->id);
            $keys = $settings->credentials ?? [];
            $helpers = $settings->helpers ?? [];

            if ($action === 'save') {
                $keys[$provider] = $key;
                if (! isset($helpers['menu'])) {
                    $helpers['menu'] = ['provider' => $provider, 'enabled' => true];
                }
                if (! $settings->master_configured) {
                    $settings->enabled = true;
                    $settings->master_configured = true;
                }
            } else {
                unset($keys[$provider]);
                foreach ($helpers as $helper => $config) {
                    if (($config['provider'] ?? null) === $provider) {
                        $helpers[$helper]['enabled'] = false;
                    }
                }
            }

            $settings->fill(['credentials' => $keys, 'helpers' => $helpers])->save();
            BusinessAudit::record($business->id, $action === 'save' ? 'AI credential saved' : 'AI credential removed', $provider);
        });

        return $this->back($business, $provider, $action === 'save' ? 'Key verified and saved.' : 'Key removed and its helpers disabled.', true);
    }

    private function back(Business $business, string $provider, string $message, bool $ok)
    {
        return redirect()->route('business.ai', ['business' => $business, 'provider' => $provider])
            ->with('ai_provider_notice', $message)->with('ai_provider_ok', $ok);
    }
}
