<?php

return [
    // Which provider class to use: "bedrock" (Claude on Amazon Bedrock) or "openai".
    'driver' => env('AI_DRIVER', 'bedrock'),

    'max_tool_rounds' => 5,
    'history_messages' => 20,
    'history_ttl_minutes' => 120,

    'bedrock' => [
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
        // Bedrock API key (bearer token), created in the AWS console.
        'token' => env('AWS_BEARER_TOKEN_BEDROCK'),
        'model' => env('BEDROCK_MODEL_ID', 'us.anthropic.claude-3-5-haiku-20241022-v1:0'),
    ],

    'openai' => [
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
    ],
];
