<?php

declare(strict_types=1);

namespace App\Services\Transcription;

/**
 * Reads the container and duration of an uploaded WAV or FLAC clip from its header, so
 * oversized clips are refused before anything is sent to Whisper.
 */
class AudioClipInspector
{
    /**
     * @return array{format: 'wav'|'flac', durationMs: int}|null null when the bytes are not a
     *     WAV or FLAC stream whose duration the header states
     */
    public function inspect(string $bytes): ?array
    {
        if (strlen($bytes) >= 12 && str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WAVE') {
            $durationMs = $this->wavDurationMs($bytes);

            return $durationMs === null ? null : ['format' => 'wav', 'durationMs' => $durationMs];
        }

        if (str_starts_with($bytes, 'fLaC')) {
            $durationMs = $this->flacDurationMs($bytes);

            return $durationMs === null ? null : ['format' => 'flac', 'durationMs' => $durationMs];
        }

        return null;
    }

    private function wavDurationMs(string $bytes): ?int
    {
        $length = strlen($bytes);
        $offset = 12;
        $byteRate = null;

        while ($offset + 8 <= $length) {
            $chunkId = substr($bytes, $offset, 4);
            $chunkSize = $this->unpackInt('V', substr($bytes, $offset + 4, 4));
            $bodyOffset = $offset + 8;

            if ($chunkId === 'fmt ' && $bodyOffset + 12 <= $length) {
                $byteRate = $this->unpackInt('V', substr($bytes, $bodyOffset + 8, 4));
            } elseif ($chunkId === 'data') {
                if ($byteRate === null || $byteRate <= 0) {
                    return null;
                }
                // Streaming writers leave the size unset (0 or 0xFFFFFFFF); use what was sent.
                $available = $length - $bodyOffset;
                $dataSize = $chunkSize > 0 && $chunkSize <= $available ? $chunkSize : $available;

                return (int) round($dataSize * 1000 / $byteRate);
            }

            $offset = $bodyOffset + $chunkSize + ($chunkSize % 2);
        }

        return null;
    }

    private function flacDurationMs(string $bytes): ?int
    {
        // The first metadata block must be STREAMINFO (type 0, 34 bytes).
        if (strlen($bytes) < 8 + 34 || (ord($bytes[4]) & 0x7F) !== 0) {
            return null;
        }

        // STREAMINFO bytes 10-17: sample rate (20 bits), channels-1 (3), bits-1 (5), total samples (36).
        $packed = $this->unpackInt('J', substr($bytes, 8 + 10, 8));
        $sampleRate = ($packed >> 44) & 0xFFFFF;
        $totalSamples = $packed & 0xFFFFFFFFF;
        if ($sampleRate === 0 || $totalSamples === 0) {
            return null;
        }

        return (int) round($totalSamples * 1000 / $sampleRate);
    }

    private function unpackInt(string $format, string $bytes): int
    {
        $values = unpack($format, $bytes);

        return $values === false ? 0 : (int) $values[1];
    }
}
