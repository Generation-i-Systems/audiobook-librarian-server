<?php

declare(strict_types=1);

$model = (string) env('TRANSCRIPTION_MODEL', 'base.en');

return [
    /*
    | Base URL of an OpenAI-compatible Whisper server (the `transcription` docker-compose
    | profile runs one at http://whisper:8000). Empty disables transcription entirely.
    */
    'whisper_url' => env('TRANSCRIPTION_WHISPER_URL'),

    /*
    | The model name reported to clients, and the model id sent to the Whisper server.
    | faster-whisper servers name models after their Hugging Face repository, so the
    | default id is derived from the short name.
    */
    'model' => $model,
    'whisper_model_id' => env('TRANSCRIPTION_WHISPER_MODEL_ID', 'Systran/faster-whisper-' . $model),

    'max_clip_seconds' => (int) env('TRANSCRIPTION_MAX_CLIP_SECONDS', 30),
    'timeout_seconds' => (int) env('TRANSCRIPTION_TIMEOUT_SECONDS', 30),
    'max_upload_bytes' => (int) env('TRANSCRIPTION_MAX_UPLOAD_BYTES', 1024 * 1024),
    'rate_limit_per_minute' => (int) env('TRANSCRIPTION_RATE_LIMIT_PER_MINUTE', 30),

    // How long the Whisper health and ffmpeg availability checks behind the
    // capability tokens are cached.
    'health_cache_seconds' => (int) env('TRANSCRIPTION_HEALTH_CACHE_SECONDS', 60),

    'ffmpeg_binary' => env('TRANSCRIPTION_FFMPEG_BINARY', 'ffmpeg'),
    'ffprobe_binary' => env('TRANSCRIPTION_FFPROBE_BINARY', 'ffprobe'),
];
