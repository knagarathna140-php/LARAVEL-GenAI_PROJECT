<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssistantRequest;
use App\Services\Assistant\AssistantService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class AssistantController extends Controller
{
    public function __invoke(AssistantRequest $request, AssistantService $assistant): JsonResponse
    {
        try {
            return response()->json($assistant->handle(
                $request->user(),
                $request->validated('message'),
                $request->validated('conversation_id'),
            ));
        } catch (RequestException|ConnectionException $e) {
            Log::error('AI provider error: '.$e->getMessage());

            return response()->json(['message' => 'The AI service is unavailable. Please try again.'], 502);
        }
    }
}
