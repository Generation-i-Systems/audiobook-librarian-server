# Speech-to-text for text sync (optional)

The apps can line up an ebook with its audiobook by transcribing short clips of the audio.
The server can do that with an optional Whisper sidecar. Nothing is stored: the clip and the
transcript exist only for the length of the request, and the logs hold ids, timing and
status, never audio or transcript text.

## Enabling it with Docker Compose

1. Add to `.env.docker`:

   ```env
   TRANSCRIPTION_WHISPER_URL=http://whisper:8000
   ```

2. Start the stack with the `transcription` profile:

   ```bash
   docker compose --env-file .env.docker --profile transcription up -d --build
   ```

   The `whisper` service runs [speaches](https://github.com/speaches-ai/speaches)
   (faster-whisper behind an OpenAI-compatible API) on the internal network only. It
   downloads the model into the `whisper-models` volume on first start (base.en is about
   150 MB). Pin a release instead of `latest-cpu` with `TRANSCRIPTION_WHISPER_IMAGE`, or use a
   `-cuda` image on a machine with an NVIDIA GPU.

3. Check `GET /api/v1/health/capabilities`: it lists `TRANSCRIPTION` once the sidecar answers
   `GET /health` (the check is cached for `TRANSCRIPTION_HEALTH_CACHE_SECONDS`, default 60), and
   also `TRANSCRIPTION_SERVER_FILES` when `ffmpeg` and `ffprobe` run in the app container
   (the image installs them).

If a transcription returns 503 with the sidecar running, the model may not be installed yet;
`docker compose exec whisper curl -X POST http://localhost:8000/v1/models/Systran/faster-whisper-base.en`
downloads it on speaches versions that do not honour `PRELOAD_MODELS`.

Any other OpenAI-compatible server that supports `response_format=verbose_json` with
`timestamp_granularities[]=word` works too; point `TRANSCRIPTION_WHISPER_URL` at it and set
`TRANSCRIPTION_WHISPER_MODEL_ID` to the model id it expects.

## Settings

| Variable | Default | Meaning |
|---|---|---|
| `TRANSCRIPTION_WHISPER_URL` | empty (disabled) | Base URL of the Whisper server |
| `TRANSCRIPTION_MODEL` | `base.en` | Model name reported to clients |
| `TRANSCRIPTION_WHISPER_MODEL_ID` | `Systran/faster-whisper-<TRANSCRIPTION_MODEL>` | Model id sent to the Whisper server |
| `TRANSCRIPTION_MAX_CLIP_SECONDS` | `30` | Longest clip transcribed; server-file requests are clamped, longer uploads refused |
| `TRANSCRIPTION_TIMEOUT_SECONDS` | `30` | Timeout for the Whisper call and for ffmpeg |
| `TRANSCRIPTION_RATE_LIMIT_PER_MINUTE` | `30` | Requests per user per minute, shared by both routes |
| `TRANSCRIPTION_MAX_UPLOAD_BYTES` | `1048576` | Largest uploaded clip |

## API

Both routes need a signed-in account with library access and return:

```json
{"words":[{"text":"Hello","startMs":0,"endMs":420,"confidence":0.93}],
 "language":"en","model":"base.en","durationMs":30000,"source":"server_file"}
```

Word times are relative to the start of the clip. `confidence` is Whisper's word
probability, or `null` when the sidecar does not report one.

- `POST /api/v1/books/{book}/files/{file}/transcriptions` (capability
  `TRANSCRIPTION_SERVER_FILES`): JSON `{startMs, durationMs, language?, expectedSize,
  expectedChunkHash?}`. `{file}` is the server filename exactly as used by
  `GET /books/{book}/download/{file}`. `expectedChunkHash` is the first chunk's `sha256` from
  the download manifest (`chunks[0].sha256`).
- `POST /api/v1/transcriptions` (capability `TRANSCRIPTION`): multipart `audio` (16 kHz mono
  WAV or FLAC, at most 30 s and 1 MB) and optional `language`.

Errors use `{"code": "...", "message": "..."}`: `FILE_NOT_FOUND` (404), `FILE_MISMATCH` (409),
`CLIP_OUT_OF_RANGE`, `AUDIO_TOO_LARGE`, `UNSUPPORTED_AUDIO`, `CLIP_TOO_LONG` (422),
`CLIP_EXTRACTION_FAILED` (500), `TRANSCRIPTION_UNAVAILABLE` (503), `TRANSCRIPTION_TIMEOUT` (504).
Missing or malformed fields give Laravel's standard 422 validation body. `docs/openapi.json`
has the full schema.
