<?php

namespace App\Services\Assistant;

use App\Models\AiInteraction;
use App\Models\User;
use App\Services\Ai\AiProvider;
use App\Services\PurchaseOrderService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class AssistantService
{
    public function __construct(
        private AiProvider $ai,
        private ToolRegistry $tools,
        private PurchaseOrderService $purchaseOrders,
    ) {
    }

    /** @return array{reply: string, conversation_id: string} */
    public function handle(User $user, string $message, ?string $conversationId = null): array
    {
        $conversationId ??= (string) Str::uuid();
        $historyKey = "assistant:history:{$user->id}:{$conversationId}";
        $pendingKey = "assistant:pending:{$user->id}:{$conversationId}";
        $startedAt = hrtime(true);
        $toolCalls = [];
        $usage = ['input_tokens' => 0, 'output_tokens' => 0];

        // Saving is decided by code, never by the model: only an exact "confirm" saves.
        $reply = $this->handleConfirmation($user, $message, $pendingKey)
            ?? $this->askModel($user, $message, $historyKey, $pendingKey, $toolCalls, $usage);

        $this->remember($historyKey, $message, $reply);
        $this->logInteraction($user, $conversationId, $message, $reply, $toolCalls, $usage, $startedAt);

        return ['reply' => $reply, 'conversation_id' => $conversationId];
    }

    private function handleConfirmation(User $user, string $message, string $pendingKey): ?string
    {
        $isConfirm = preg_match('/^\s*confirm(ed)?[\s.!]*$/i', $message) === 1;
        $isCancel = preg_match('/^\s*(cancel|discard)[\s.!]*$/i', $message) === 1;
        if (! $isConfirm && ! $isCancel) {
            return null;
        }

        $draft = Cache::get($pendingKey);
        if (! $draft) {
            return 'There is no draft purchase order waiting for confirmation.';
        }
        Cache::forget($pendingKey);

        if ($isCancel) {
            return 'Okay, the draft was discarded and nothing was saved.';
        }
        if (! $user->isPurchasing()) {
            return 'Only the Purchasing team can create purchase orders.';
        }

        $po = $this->purchaseOrders->createDraft($user, $draft['supplier_id'], $draft['lines']);
        $lines = $po->lines->map(fn ($l) => "{$l->quantity} x {$l->product->name} {$l->product->thickness_cm}cm")->implode(', ');

        return "Saved draft {$po->po_number} for {$po->supplier->name}: {$lines}.";
    }

    private function askModel(
        User $user,
        string $message,
        string $historyKey,
        string $pendingKey,
        array &$toolCalls,
        array &$usage,
    ): string
    {
        $messages = Cache::get($historyKey, []);
        $messages[] = ['role' => 'user', 'content' => $message];
        $definitions = $this->tools->definitions();
        $system = $this->systemPrompt($user);

        for ($round = 0; $round < config('ai.max_tool_rounds'); $round++) {
            $response = $this->ai->chat($system, $messages, $definitions);
            $usage['input_tokens'] += (int) ($response['usage']['input_tokens'] ?? 0);
            $usage['output_tokens'] += (int) ($response['usage']['output_tokens'] ?? 0);

            if ($response['tool_calls'] === []) {
                return trim($response['text']) ?: 'Sorry, I could not produce an answer.';
            }

            $messages[] = ['role' => 'assistant', 'content' => $response['text'], 'tool_calls' => $response['tool_calls']];
            foreach ($response['tool_calls'] as $call) {
                $result = $this->tools->execute($call['name'], $call['args'], $user, $pendingKey);
                $toolCalls[] = ['name' => $call['name'], 'arguments' => $call['args']];
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $call['id'],
                    'name' => $call['name'],
                    'content' => json_encode($result),
                ];
            }
        }

        return 'Sorry, that question needed too many steps. Please try a simpler one.';
    }

    private function systemPrompt(User $user): string
    {
        return "You are the inventory and purchasing assistant inside a stone and quartz ERP for a US distributor.\n"
            ."Today is ".today()->toFormattedDateString().". The user's role is: {$user->role}.\n"
            ."Rules:\n"
            ."- Answer ONLY from tool results. Never guess numbers, stock, costs or dates.\n"
            ."- If a tool returns an error or 'forbidden', explain politely using its message. Do not try to work around it.\n"
            ."- Never reveal costs, freight or bank details unless a tool returned them.\n"
            ."- For a draft PO, show the draft clearly and tell the user to reply \"confirm\" to save it. Never say it is saved.\n"
            ."- Use the earlier conversation to resolve follow-ups like 'and in Dallas?'.\n"
            ."- Keep answers short and plain.";
    }

    /** Only the plain question and answer are remembered, so tool calls never get cut in half. */
    private function remember(string $historyKey, string $message, string $reply): void
    {
        $history = Cache::get($historyKey, []);
        $history[] = ['role' => 'user', 'content' => $message];
        $history[] = ['role' => 'assistant', 'content' => $reply];

        Cache::put(
            $historyKey,
            array_slice($history, -config('ai.history_messages')),
            now()->addMinutes(config('ai.history_ttl_minutes')),
        );
    }

    private function logInteraction(
        User $user,
        string $conversationId,
        string $message,
        string $reply,
        array $toolCalls,
        array $usage,
        int $startedAt,
    ): void {
        AiInteraction::create([
            'user_id' => $user->id,
            'conversation_id' => $conversationId,
            'question' => $message,
            'tool_calls' => $toolCalls,
            'answer' => $reply,
            'input_tokens' => $usage['input_tokens'],
            'output_tokens' => $usage['output_tokens'],
            'response_time_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
            'created_at' => now(),
        ]);
    }
}
