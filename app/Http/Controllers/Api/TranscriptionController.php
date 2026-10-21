<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Transcription\ClipTranscriptionService;
use App\Services\Transcription\TranscriptionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * Word-timed speech-to-text for short clips (text sync). See ClipTranscriptionService.
 */
class TranscriptionController extends Controller
{
    private const LANGUAGE_RULE = ['nullable', 'string', 'regex:/^[a-z]{2,3}$/'];

    public function __construct(private readonly ClipTranscriptionService $transcriptions)
    {
    }

    public function transcribeBookFile(Request $request, string $book, string $file): JsonResponse
    {
        $validated = $request->validate([
            'startMs' => ['required', 'integer', 'min:0'],
            'durationMs' => ['required', 'integer', 'min:1'],
            'expectedSize' => ['required', 'integer', 'min:0'],
            'expectedChunkHash' => ['nullable', 'string', 'max:80'],
            'language' => self::LANGUAGE_RULE,
        ]);

        return $this->respond(fn (): array => $this->transcriptions->transcribeBookFile(
            $book,
            urldecode($file),
            (int) $validated['startMs'],
            (int) $validated['durationMs'],
            (int) $validated['expectedSize'],
            $validated['expectedChunkHash'] ?? null,
            $validated['language'] ?? null
        ), ['book_id' => $book]);
    }

    public function transcribeUpload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'audio' => ['required', 'file'],
            'language' => self::LANGUAGE_RULE,
        ]);

        /** @var UploadedFile $audio */
        $audio = $validated['audio'];
        $bytes = (string) file_get_contents($audio->getRealPath());

        return $this->respond(
            fn (): array => $this->transcriptions->transcribeUpload($bytes, $validated['language'] ?? null),
            []
        );
    }

    /**
     * @param callable(): array<string, mixed> $transcribe
     * @param array<string, string> $logContext
     */
    private function respond(callable $transcribe, array $logContext): JsonResponse
    {
        try {
            return response()->json($transcribe());
        } catch (TranscriptionException $e) {
            Log::info('Transcription refused', array_merge($logContext, [
                'user_id' => auth()->id(),
                'status' => $e->status,
                'code' => $e->errorCode,
            ]));

            return response()->json(['code' => $e->errorCode, 'message' => $e->getMessage()], $e->status);
        }
    }
}
