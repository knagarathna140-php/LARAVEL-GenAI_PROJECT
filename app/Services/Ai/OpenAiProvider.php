<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;

/** Any OpenAI-compatible chat completions API. Proves the provider is swappable. */
class OpenAiProvider implements AiProvider
{
    public function chat(string $system, array $messages, array $tools): array
    {
        $cfg = config('ai.openai');

        $payload = [
            'model' => $cfg['model'],
            'temperature' => 0,
            'messages' => array_merge([['role' => 'system', 'content' => $system]], $this->convertMessages($messages)),
        ];
        if ($tools !== []) {
            $payload['tools'] = array_map(fn ($t) => ['type' => 'function', 'function' => [
                'name' => $t['name'],
                'description' => $t['description'],
                'parameters' => $t['parameters'],
            ]], $tools);
        }

        $response = Http::withToken((string) $cfg['key'])->timeout(60)
            ->post(rtrim($cfg['base_url'], '/').'/chat/completions', $payload)->throw()->json();

        $message = $response['choices'][0]['message'] ?? [];
        $calls = array_map(fn ($c) => [
            'id' => $c['id'],
            'name' => $c['function']['name'],
            'args' => json_decode($c['function']['arguments'] ?: '{}', true) ?: [],
        ], $message['tool_calls'] ?? []);

        $usage = $response['usage'] ?? [];

        return [
            'text' => (string) ($message['content'] ?? ''),
            'tool_calls' => $calls,
            'usage' => [
                'input_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
                'output_tokens' => (int) ($usage['completion_tokens'] ?? 0),
                'total_tokens' => (int) ($usage['total_tokens'] ?? 0),
            ],
        ];
    }

    private function convertMessages(array $messages): array
    {
        return array_map(function ($m) {
            if ($m['role'] === 'tool') {
                return ['role' => 'tool', 'tool_call_id' => $m['tool_call_id'], 'content' => $m['content']];
            }
            if ($m['role'] === 'assistant' && ! empty($m['tool_calls'])) {
                return [
                    'role' => 'assistant',
                    'content' => $m['content'] ?: null,
                    'tool_calls' => array_map(fn ($c) => [
                        'id' => $c['id'],
                        'type' => 'function',
                        'function' => ['name' => $c['name'], 'arguments' => json_encode((object) $c['args'])],
                    ], $m['tool_calls']),
                ];
            }

            return ['role' => $m['role'], 'content' => $m['content']];
        }, $messages);
    }
}
