<?php

namespace Tests\Feature;

use App\Models\Narrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NarratorNormalizationTest extends TestCase
{
    use RefreshDatabase;

    public function testNormalizesNarratorNamesCorrectly()
    {
        $testCases = [
            'John Doe' => 'john doe',
            'JOHN DOE' => 'john doe',
            'Jóhñ Döe' => 'jóhñ döe',
            '  John  Doe  ' => 'john doe',
            "John\nDoe" => 'john doe',
            '  Jóhñ  Döe  ' => 'jóhñ döe',
            'JOHN DÖE' => 'john döe',
        ];

        foreach ($testCases as $input => $expected) {
            $this->assertEquals(
                $expected,
                Narrator::normalizeName($input),
                "Failed to normalize: {$input}"
            );
        }
    }

    public function testUpdatesNormalizedNameWhenCreatingNarrator()
    {
        $narrator = Narrator::create(['name' => '  Jóhñ  Döe  ']);

        $this->assertDatabaseHas('narrators', [
            'id' => $narrator->id,
            'name' => '  Jóhñ  Döe  ', // The exact input is preserved
            'normalized_name' => 'jóhñ döe',
        ]);
    }

    public function testUpdatesNormalizedNameWhenUpdatingNarrator()
    {
        $narrator = Narrator::create(['name' => 'John Doe']);

        $narrator->update(['name' => '  Jóhñ  Döe  ']);

        $this->assertDatabaseHas('narrators', [
            'id' => $narrator->id,
            'name' => '  Jóhñ  Döe  ', // The exact input is preserved
            'normalized_name' => 'jóhñ döe',
        ]);
    }
}
