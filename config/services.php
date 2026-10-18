<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-3.6-flash'),
        'chat_timeout' => (int) env('GEMINI_CHAT_TIMEOUT', 900),
        'pipeline_delay_seconds' => (int) env('GEMINI_PIPELINE_DELAY_SECONDS', 8),
        'embedding_model' => env('GEMINI_EMBEDDING_MODEL', 'gemini-embedding-001'),
        'embedding_batch' => (int) env('GEMINI_EMBEDDING_BATCH', 40),
        'embedding_dimensions' => (int) env('GEMINI_EMBEDDING_DIMENSIONS', 768),
        'embedding_timeout' => (int) env('GEMINI_EMBEDDING_TIMEOUT', 300),
        'pro_key' => env('GEMINI_PRO_API_KEY'),
    ],

    /*
    | Structured itinerary: vector finds product_id → full meta → formatter model.
    | Swap ITINERARY_FORMATTER / ITINERARY_FORMATTER_MODEL for a fine-tuned model later.
    */
    'itinerary' => [
        'formatter' => env('ITINERARY_FORMATTER', 'gemini'),
        'formatter_model' => env('ITINERARY_FORMATTER_MODEL', env('GEMINI_MODEL', 'gemini-3.6-flash')),
        'catalog_batch_size' => (int) env('ITINERARY_CATALOG_BATCH_SIZE', 12),
        'catalog_retries' => (int) env('ITINERARY_CATALOG_RETRIES', 3),
    ],

    /*
    | Nominatim (OpenStreetMap) geocoding for itinerary day cards.
    | Cache results in place_coordinates. Respect ~1 req/sec.
    */
    /*
    | Vertex AI supervised fine-tuning (GCS + tuningJobs).
    | Credentials: storage/app/json/google-credentials.json (service account).
    */
    'vertex' => [
        'project_id' => env('VERTEX_PROJECT_ID', 'project-3a79b2fb-7d69-4475-9cf'),
        'location' => env('VERTEX_LOCATION', 'us-central1'),
        'gcs_bucket' => env('VERTEX_GCS_BUCKET', 'travel-dataset-storage'),
        'credentials' => env('GOOGLE_APPLICATION_CREDENTIALS', storage_path('app/json/google-credentials.json')),
        'base_model' => env('VERTEX_BASE_MODEL', 'gemini-1.5-flash-002'),
        'tuned_display_name' => env('VERTEX_TUNED_DISPLAY_NAME', 'travel-route-model-v1'),
        'epoch_count' => (int) env('VERTEX_EPOCH_COUNT', 4),
    ],

    'nominatim' => [
        'enabled' => (bool) env('NOMINATIM_ENABLED', true),
        'base_url' => env('NOMINATIM_BASE_URL', 'https://nominatim.openstreetmap.org'),
        'user_agent' => env('NOMINATIM_USER_AGENT', 'travel.loc-itinerary/1.0 (admin full-tour)'),
        'email' => env('NOMINATIM_EMAIL', ''),
        'country_codes' => env('NOMINATIM_COUNTRY_CODES', 'it,ch,at'),
        'rate_limit_ms' => (int) env('NOMINATIM_RATE_LIMIT_MS', 1100),
        'max_day_span_km' => (float) env('NOMINATIM_MAX_DAY_SPAN_KM', 70),
        'max_day_jump_km' => (float) env('NOMINATIM_MAX_DAY_JUMP_KM', 120),
    ],

];
