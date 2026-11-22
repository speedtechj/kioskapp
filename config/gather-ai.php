<?php

return [
    // Standard text prices in USD per million tokens. Keep these in sync with provider pricing.
    'providers' => [
        'openai' => ['label' => 'OpenAI · GPT', 'model' => 'gpt-4.1-mini', 'input' => 0.40, 'output' => 1.60],
        'anthropic' => ['label' => 'Anthropic · Claude', 'model' => 'claude-haiku-4-5', 'input' => 1, 'output' => 5],
        'groq' => ['label' => 'Groq', 'model' => 'openai/gpt-oss-20b', 'input' => 0.075, 'output' => 0.30],
        'gemini' => ['label' => 'Google · Gemini', 'model' => 'gemini-2.5-flash-lite', 'input' => 0.10, 'output' => 0.40],
    ],
    'helpers' => ['menu' => 'Menu Writing Assistant', 'brief' => 'AI Daily Brief', 'ask' => 'Ask Gather', 'customer' => 'Ask Joy · Customer app'],
    'languages' => [
        'en' => 'English', 'fil' => 'Filipino', 'ceb' => 'Cebuano', 'fr' => 'French',
        'es' => 'Spanish', 'ja' => 'Japanese', 'ko' => 'Korean', 'zh-Hans' => 'Simplified Chinese',
        'vi' => 'Vietnamese', 'th' => 'Thai', 'id' => 'Indonesian', 'ar' => 'Arabic',
    ],
];
