<?php

namespace App\Providers;

use App\Services\Ai\AiProvider;
use App\Services\Ai\BedrockProvider;
use App\Services\Ai\OpenAiProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Swap the AI provider with AI_DRIVER in .env.
        $this->app->bind(AiProvider::class, fn () => match (config('ai.driver')) {
            'openai' => new OpenAiProvider,
            default => new BedrockProvider,
        });
    }
}
