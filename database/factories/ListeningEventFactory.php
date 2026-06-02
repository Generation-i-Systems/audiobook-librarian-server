<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ListeningEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ListeningEvent>
 */
class ListeningEventFactory extends Factory
{
    protected $model = ListeningEvent::class;

    public function definition(): array
    {
        $now = (int) (microtime(true) * 1000);
        return [
            'id'               => Str::uuid()->toString(),
            'user_id'          => null,
            'book_id'          => null,
            'event_type'       => 'PLAY_START',
            'timestamp_ms'     => $now,
            'position_ms'      => 0,
            'metadata'         => [],
            'device_id'        => 'test-device',
            'timezone'         => 'UTC',
            'sync_status'      => 'SYNCED',
            'created_at'       => $now,
            'synced_at'        => $now,
            'migrated_from'    => null,
            'migration_source_id' => null,
        ];
    }

    public function playStart(int $timestampMs, int $positionMs = 0, ?array $metadata = null): static
    {
        return $this->state([
            'event_type'   => 'PLAY_START',
            'timestamp_ms' => $timestampMs,
            'position_ms'  => $positionMs,
            'metadata'     => $metadata ?? [],
        ]);
    }

    public function playPause(int $timestampMs, int $positionMs = 0): static
    {
        return $this->state([
            'event_type'   => 'PLAY_PAUSE',
            'timestamp_ms' => $timestampMs,
            'position_ms'  => $positionMs,
        ]);
    }

    public function sessionEnd(int $timestampMs, int $positionMs = 0, int $sessionDurationMs = 0): static
    {
        return $this->state([
            'event_type'   => 'SESSION_END',
            'timestamp_ms' => $timestampMs,
            'position_ms'  => $positionMs,
            'metadata'     => ['sessionDurationMs' => $sessionDurationMs],
        ]);
    }
}
