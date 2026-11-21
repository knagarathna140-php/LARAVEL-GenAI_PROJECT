<?php

namespace App\Services\Ai;

/**
 * Provider-neutral chat interface with tool calling. To add a provider,
 * implement this and register it in AppServiceProvider.
 *
 * Messages are neutral arrays:
 *  ['role' => 'user', 'content' => '...']
 *  ['role' => 'assistant', 'content' => '...', 'tool_calls' => [['id', 'name', 'args']]]
 *  ['role' => 'tool', 'tool_call_id' => '...', 'name' => '...', 'content' => '<json string>']
 *
 * Tools are ['name', 'description', 'parameters' => JSON schema].
 */
interface AiProvider
{
    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>  $tools
     * @return array{text: string, tool_calls: array<int, array{id: string, name: string, args: array<string, mixed>}>, usage?: array{input_tokens?: int, output_tokens?: int, total_tokens?: int}}
     */
    public function chat(string $system, array $messages, array $tools): array;
}
