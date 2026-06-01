<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Book;
use App\Models\ListeningEvent;
use Illuminate\Support\Str;

class DayTimelineTest extends ApiTestCase
{
    private const DATE = '2025-05-13';
    private const TIMEZONE = 'UTC';
    // 2025-05-13 00:00:00 UTC in ms
    private const DAY_START_MS = 1747094400000;

    private function ms(int $hoursFromMidnight, int $minutesExtra = 0): int
    {
        return self::DAY_START_MS + ($hoursFromMidnight * 3600000) + ($minutesExtra * 60000);
    }

    private function createEvent(Book $book, array $attributes = []): ListeningEvent
    {
        $defaults = [
            'id'               => Str::uuid()->toString(),
            'user_id'          => $this->user->id,
            'book_id'          => $book->id,
            'event_type'       => 'PLAY_START',
            'timestamp_ms'     => $this->ms(10),
            'position_ms'      => 0,
            'metadata'         => [],
            'device_id'        => 'device-1',
            'timezone'         => self::TIMEZONE,
            'sync_status'      => 'SYNCED',
            'created_at'       => $this->ms(10),
            'synced_at'        => $this->ms(10),
        ];

        return ListeningEvent::create(array_merge($defaults, $attributes));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function returns_empty_segments_for_day_with_no_events(): void
    {
        $response = $this->getJson('/api/v1/statistics/timeline/day?date=' . self::DATE . '&timezone=' . self::TIMEZONE);

        $response->assertOk()
            ->assertJsonStructure(['date', 'timezone', 'total_listening_ms', 'segments'])
            ->assertJson([
                'date'              => self::DATE,
                'timezone'          => self::TIMEZONE,
                'total_listening_ms' => 0,
                'segments'          => [],
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function returns_one_segment_for_play_start_pause_pair(): void
    {
        $book = Book::factory()->create(['title' => 'The Name of the Wind']);

        $this->createEvent($book, [
            'event_type'   => 'PLAY_START',
            'timestamp_ms' => $this->ms(10),
            'position_ms'  => 14400000,
        ]);
        $this->createEvent($book, [
            'event_type'   => 'PLAY_PAUSE',
            'timestamp_ms' => $this->ms(11),
            'position_ms'  => 18000000,
        ]);

        $response = $this->getJson('/api/v1/statistics/timeline/day?date=' . self::DATE . '&timezone=' . self::TIMEZONE);

        $response->assertOk();
        $segments = $response->json('segments');
        $this->assertCount(1, $segments);

        $seg = $segments[0];
        $this->assertEquals($book->id, $seg['book_id']);
        $this->assertEquals('The Name of the Wind', $seg['book_title']);
        $this->assertEquals($this->ms(10), $seg['start_ms']);
        $this->assertEquals($this->ms(11), $seg['end_ms']);
        $this->assertEquals(3600000, $seg['duration_ms']);
        $this->assertEquals(14400000, $seg['start_position_ms']);
        $this->assertEquals(18000000, $seg['end_position_ms']);
        $this->assertFalse($seg['is_orphaned']);
        $response->assertJson(['total_listening_ms' => 3600000]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function chapter_change_produces_two_segments(): void
    {
        $book = Book::factory()->create(['title' => 'Dune']);

        $this->createEvent($book, [
            'event_type'   => 'PLAY_START',
            'timestamp_ms' => $this->ms(10),
            'position_ms'  => 0,
            'metadata'     => ['chapterName' => 'Chapter 1', 'chapterIndex' => 0],
        ]);
        $this->createEvent($book, [
            'event_type'   => 'CHAPTER_CHANGE',
            'timestamp_ms' => $this->ms(10, 30),
            'position_ms'  => 1800000,
            'metadata'     => ['chapterName' => 'Chapter 2', 'chapterIndex' => 1],
        ]);
        $this->createEvent($book, [
            'event_type'   => 'PLAY_PAUSE',
            'timestamp_ms' => $this->ms(11),
            'position_ms'  => 3600000,
        ]);

        $response = $this->getJson('/api/v1/statistics/timeline/day?date=' . self::DATE . '&timezone=' . self::TIMEZONE);

        $response->assertOk();
        $segments = $response->json('segments');
        $this->assertCount(2, $segments);
        $this->assertEquals('Chapter 1', $segments[0]['chapter_name']);
        $this->assertEquals('Chapter 2', $segments[1]['chapter_name']);
        $this->assertEquals($this->ms(10), $segments[0]['start_ms']);
        $this->assertEquals($this->ms(10, 30), $segments[0]['end_ms']);
        $this->assertEquals($this->ms(10, 30), $segments[1]['start_ms']);
        $this->assertEquals($this->ms(11), $segments[1]['end_ms']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function unclosed_session_is_marked_orphaned(): void
    {
        $book = Book::factory()->create();

        $this->createEvent($book, [
            'event_type'   => 'PLAY_START',
            'timestamp_ms' => $this->ms(10),
            'position_ms'  => 0,
        ]);
        // No closing event

        $response = $this->getJson('/api/v1/statistics/timeline/day?date=' . self::DATE . '&timezone=' . self::TIMEZONE);

        $response->assertOk();
        $segments = $response->json('segments');
        $this->assertCount(1, $segments);
        $this->assertTrue($segments[0]['is_orphaned']);
        $this->assertEquals($this->ms(10), $segments[0]['start_ms']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function two_devices_produce_independent_segments(): void
    {
        $book = Book::factory()->create(['title' => 'Foundation']);

        // Device 1: 10:00 – 11:00
        $this->createEvent($book, [
            'event_type'   => 'PLAY_START',
            'timestamp_ms' => $this->ms(10),
            'device_id'    => 'device-1',
            'position_ms'  => 0,
        ]);
        $this->createEvent($book, [
            'event_type'   => 'PLAY_PAUSE',
            'timestamp_ms' => $this->ms(11),
            'device_id'    => 'device-1',
            'position_ms'  => 3600000,
        ]);

        // Device 2: 10:30 – 11:30 (overlapping)
        $this->createEvent($book, [
            'event_type'   => 'PLAY_START',
            'timestamp_ms' => $this->ms(10, 30),
            'device_id'    => 'device-2',
            'position_ms'  => 0,
        ]);
        $this->createEvent($book, [
            'event_type'   => 'PLAY_PAUSE',
            'timestamp_ms' => $this->ms(11, 30),
            'device_id'    => 'device-2',
            'position_ms'  => 3600000,
        ]);

        $response = $this->getJson('/api/v1/statistics/timeline/day?date=' . self::DATE . '&timezone=' . self::TIMEZONE);

        $response->assertOk();
        $segments = $response->json('segments');
        $this->assertCount(2, $segments);

        $devices = array_column($segments, 'device_id');
        $this->assertContains('device-1', $devices);
        $this->assertContains('device-2', $devices);

        foreach ($segments as $seg) {
            $this->assertFalse($seg['is_orphaned']);
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function events_outside_requested_day_are_excluded(): void
    {
        $book = Book::factory()->create();

        // Previous day event
        $this->createEvent($book, [
            'event_type'   => 'PLAY_START',
            'timestamp_ms' => self::DAY_START_MS - 7200000, // 2 hours before midnight
            'position_ms'  => 0,
        ]);
        $this->createEvent($book, [
            'event_type'   => 'PLAY_PAUSE',
            'timestamp_ms' => self::DAY_START_MS - 3600000, // 1 hour before midnight
            'position_ms'  => 3600000,
        ]);

        $response = $this->getJson('/api/v1/statistics/timeline/day?date=' . self::DATE . '&timezone=' . self::TIMEZONE);

        $response->assertOk()
            ->assertJson(['segments' => [], 'total_listening_ms' => 0]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/statistics/timeline/day?date=' . self::DATE . '&timezone=' . self::TIMEZONE, [
            'Authorization' => '',
        ]);

        $response->assertUnauthorized();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function validates_date_param_is_required(): void
    {
        $response = $this->getJson('/api/v1/statistics/timeline/day?timezone=' . self::TIMEZONE);

        $response->assertUnprocessable();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function book_title_is_resolved_from_books_table(): void
    {
        $book = Book::factory()->create(['title' => 'Mistborn']);

        $this->createEvent($book, [
            'event_type'   => 'PLAY_START',
            'timestamp_ms' => $this->ms(10),
            'position_ms'  => 0,
        ]);
        $this->createEvent($book, [
            'event_type'   => 'PLAY_PAUSE',
            'timestamp_ms' => $this->ms(11),
            'position_ms'  => 3600000,
        ]);

        $response = $this->getJson('/api/v1/statistics/timeline/day?date=' . self::DATE . '&timezone=' . self::TIMEZONE);

        $response->assertOk();
        $this->assertEquals('Mistborn', $response->json('segments.0.book_title'));
    }
}
