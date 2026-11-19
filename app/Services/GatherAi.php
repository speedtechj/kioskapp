<?php

namespace App\Services;

use App\Models\BusinessAiSetting;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class GatherAi
{
    private const OUTPUT_TOKENS = 1500;

    public static function canManage(?User $user, int $businessId): bool
    {
        return $user && in_array(Access::membership($user, $businessId)?->pivot->role, ['owner', 'manager'], true);
    }

    public static function settings(int $businessId): BusinessAiSetting
    {
        return BusinessAiSetting::firstOrCreate(['business_id' => $businessId])->refresh();
    }

    public static function usage(int $businessId, int $monthsAgo = 0)
    {
        $month = now()->startOfMonth()->subMonths($monthsAgo);

        return DB::table('business_ai_usage')->where('business_id', $businessId)
            ->where('created_at', '>=', $month)->where('created_at', '<', $month->copy()->addMonth());
    }

    public function test(string $provider, string $key): bool
    {
        $model = config("gather-ai.providers.$provider.model");

        try {
            $response = match ($provider) {
                'openai' => $this->http()->withToken($key)->get("https://api.openai.com/v1/models/$model"),
                'anthropic' => $this->claude($key)->get("https://api.anthropic.com/v1/models/$model"),
                'gemini' => $this->gemini($key)->get("https://generativelanguage.googleapis.com/v1beta/models/$model"),
            };

            return $response->successful();
        } catch (ConnectionException) {
            return false;
        }
    }

    public function generate(int $businessId, string $helper, string $instruction, string $data): string
    {
        abort_unless(self::canManage(auth()->user(), $businessId), 403);
        self::settings($businessId);

        [$id, $provider, $key, $rate] = DB::transaction(function () use ($businessId, $helper, $instruction, $data) {
            $settings = BusinessAiSetting::lockForUpdate()->findOrFail($businessId);
            $helperSettings = $settings->helpers[$helper] ?? [];
            $provider = $helperSettings['provider'] ?? '';
            $key = $settings->credentials[$provider] ?? null;
            $rate = config('gather-ai.providers')[$provider] ?? null;
            if (! $settings->enabled || ! ($helperSettings['enabled'] ?? false) || ! $key || ! $rate) {
                throw ValidationException::withMessages(['ai' => 'Enable this helper and connect its provider in AI Settings.']);
            }

            // Reserve a conservative estimate before dispatch so overlapping requests count toward the limit.
            $reserve = (int) ceil((strlen($instruction.$data) + 128) * $rate['input'] + self::OUTPUT_TOKENS * $rate['output']);
            if ($settings->pause_at_limit && self::usage($businessId)->sum('cost_micros') + $reserve > $settings->monthly_limit_cents * 10000) {
                throw ValidationException::withMessages(['ai' => 'This request would exceed the estimated monthly limit.']);
            }

            $id = DB::table('business_ai_usage')->insertGetId([
                'business_id' => $businessId, 'helper' => $helper, 'provider' => $provider, 'model' => $rate['model'],
                'cost_micros' => $reserve, 'status' => 'pending', 'created_at' => now(),
            ]);

            return [$id, $provider, $key, $rate];
        });

        $usage = DB::table('business_ai_usage')->where('id', $id);
        try {
            [$text, $input, $output] = $this->request($provider, $key, $rate['model'], $instruction, $data);
        } catch (ConnectionException) {
            $usage->update(['status' => 'uncertain']);
            throw ValidationException::withMessages(['ai' => 'The provider timed out. Gather has retained the estimated cost because the provider may have processed the request.']);
        } catch (RequestException $exception) {
            $status = $exception->response->status();
            $usage->update($status >= 500 ? ['status' => 'uncertain'] : ['status' => 'failed', 'cost_micros' => 0]);
            throw ValidationException::withMessages(['ai' => 'The provider rejected the request. Check the key, API billing, and provider availability.']);
        }

        $usage->update([
            'status' => 'complete',
            ...($input !== null && $output !== null ? ['cost_micros' => (int) ceil($input * $rate['input'] + $output * $rate['output'])] : []),
        ]);
        if (trim((string) $text) === '') {
            throw ValidationException::withMessages(['ai' => 'The provider returned an empty response.']);
        }

        return trim($text);
    }

    private function request(string $provider, string $key, string $model, string $instruction, string $data): array
    {
        if ($provider === 'openai') {
            $result = $this->http()->withToken($key)->post('https://api.openai.com/v1/chat/completions', [
                'model' => $model, 'max_completion_tokens' => self::OUTPUT_TOKENS,
                'messages' => [['role' => 'system', 'content' => $instruction], ['role' => 'user', 'content' => $data]],
            ])->throw()->json();

            return [data_get($result, 'choices.0.message.content'), data_get($result, 'usage.prompt_tokens'), data_get($result, 'usage.completion_tokens')];
        }

        if ($provider === 'anthropic') {
            $result = $this->claude($key)->post('https://api.anthropic.com/v1/messages', [
                'model' => $model, 'max_tokens' => self::OUTPUT_TOKENS, 'system' => $instruction,
                'messages' => [['role' => 'user', 'content' => $data]],
            ])->throw()->json();

            return [collect(data_get($result, 'content', []))->where('type', 'text')->pluck('text')->implode("\n"), data_get($result, 'usage.input_tokens'), data_get($result, 'usage.output_tokens')];
        }

        $result = $this->gemini($key)->post("https://generativelanguage.googleapis.com/v1beta/models/$model:generateContent", [
            'systemInstruction' => ['parts' => [['text' => $instruction]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $data]]]],
            'generationConfig' => ['maxOutputTokens' => self::OUTPUT_TOKENS, 'thinkingConfig' => ['thinkingBudget' => 0]],
        ])->throw()->json();

        return [collect(data_get($result, 'candidates.0.content.parts', []))->pluck('text')->implode("\n"),
            data_get($result, 'usageMetadata.promptTokenCount'),
            data_get($result, 'usageMetadata.candidatesTokenCount') === null ? null : data_get($result, 'usageMetadata.candidatesTokenCount') + data_get($result, 'usageMetadata.thoughtsTokenCount', 0)];
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()->connectTimeout(5)->timeout(25)->withoutRedirecting();
    }

    private function claude(string $key): PendingRequest
    {
        return $this->http()->withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01']);
    }

    private function gemini(string $key): PendingRequest
    {
        return $this->http()->withHeaders(['x-goog-api-key' => $key]);
    }
}
