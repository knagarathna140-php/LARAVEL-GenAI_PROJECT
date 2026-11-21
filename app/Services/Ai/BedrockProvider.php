<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;

/** Claude on Amazon Bedrock through the Converse API, using a Bedrock API key (bearer token). */
class BedrockProvider implements AiProvider
{
    public function chat(string $system, array $messages, array $tools): array
    {
        $cfg = config('ai.bedrock');
        $url = "https://bedrock-runtime.{$cfg['region']}.amazonaws.com/model/".rawurlencode($cfg['model']).'/converse';

        $payload = [
            'system' => [['text' => $system]],
            'messages' => $this->convertMessages($messages),
            'inferenceConfig' => ['maxTokens' => 1024, 'temperature' => 0],
        ];
        if ($tools !== []) {
            $payload['toolConfig'] = ['tools' => array_map(fn ($t) => ['toolSpec' => [
                'name' => $t['name'],
                'description' => $t['description'],
                'inputSchema' => ['json' => $t['parameters']],
            ]], $tools)];
        }

        $response = Http::withToken((string) $cfg['token'])->timeout(60)->post($url, $payload)->throw()->json();

        $text = '';
        $calls = [];
        foreach ($response['output']['message']['content'] ?? [] as $block) {
            if (isset($block['text'])) {
                $text .= $block['text'];
            }
            if (isset($block['toolUse'])) {
                $calls[] = [
                    'id' => $block['toolUse']['toolUseId'],
                    'name' => $block['toolUse']['name'],
                    'args' => (array) ($block['toolUse']['input'] ?? []),
                ];
            }
        }

        $usage = $response['usage'] ?? [];

        return [
            'text' => $text,
            'tool_calls' => $calls,
            'usage' => [
                'input_tokens' => (int) ($usage['inputTokens'] ?? 0),
                'output_tokens' => (int) ($usage['outputTokens'] ?? 0),
                'total_tokens' => (int) ($usage['totalTokens'] ?? 0),
            ],
        ];
    }

    /** Converse wants tool results inside one user message, so consecutive tool messages are merged. */
    private function convertMessages(array $messages): array
    {
        $out = [];
        foreach ($messages as $m) {
            if ($m['role'] === 'tool') {
                $block = ['toolResult' => [
                    'toolUseId' => $m['tool_call_id'],
                    'content' => [['text' => $m['content']]],
                ]];
                $last = array_key_last($out);
                if ($last !== null && $out[$last]['role'] === 'user' && isset($out[$last]['content'][0]['toolResult'])) {
                    $out[$last]['content'][] = $block;
                } else {
                    $out[] = ['role' => 'user', 'content' => [$block]];
                }
            } elseif ($m['role'] === 'assistant') {
                $content = [];
                if (($m['content'] ?? '') !== '') {
                    $content[] = ['text' => $m['content']];
                }
                foreach ($m['tool_calls'] ?? [] as $call) {
                    $content[] = ['toolUse' => [
                        'toolUseId' => $call['id'],
                        'name' => $call['name'],
                        'input' => (object) $call['args'],
                    ]];
                }
                $out[] = ['role' => 'assistant', 'content' => $content ?: [['text' => '...']]];
            } else {
                $out[] = ['role' => 'user', 'content' => [['text' => $m['content']]]];
            }
        }

        return $out;
    }
}
