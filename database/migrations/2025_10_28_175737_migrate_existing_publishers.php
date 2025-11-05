<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Get all unique publisher names from the books table
        $publishers = DB::table('books')
            ->select('publisher')
            ->whereNotNull('publisher')
            ->where('publisher', '!=', '')
            ->distinct()
            ->pluck('publisher');

        // Create a mapping of publisher names to their IDs
        $publisherMap = [];
        $slugCounts = [];

        foreach ($publishers as $publisherName) {
            if (empty(trim($publisherName))) {
                continue;
            }

            // Generate a slug for the publisher
            $baseSlug = Str::slug($publisherName);
            $slug = $baseSlug;
            $count = 1;

            // Check if we've seen this slug before
            if (isset($slugCounts[$baseSlug])) {
                $count = ++$slugCounts[$baseSlug];
                $slug = $baseSlug . '-' . $count;
            } else {
                $slugCounts[$baseSlug] = 1;
            }

            // Create a new publisher
            $publisher = new \App\Models\Publisher([
                'name' => $publisherName,
                'slug' => $slug,
                'is_active' => true,
            ]);

            // Save the publisher and handle any duplicate slug errors
            while (true) {
                try {
                    $publisher->save();
                    break;
                } catch (\Illuminate\Database\QueryException $e) {
                    if (str_contains($e->getMessage(), 'publishers_slug_unique')) {
                        // If there's a duplicate slug, append a number and try again
                        $count++;
                        $publisher->slug = $baseSlug . '-' . $count;
                        $slugCounts[$baseSlug] = $count;
                    } else {
                        // If it's a different error, rethrow it
                        throw $e;
                    }
                }
            }

            $publisherMap[$publisherName] = $publisher->id;
        }

        // Update the books table with the new publisher_id
        foreach ($publisherMap as $name => $id) {
            DB::table('books')
                ->where('publisher', $name)
                ->update(['publisher_id' => $id]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Get all publishers and their names
        $publishers = \App\Models\Publisher::all();

        // Update the books table with the publisher names
        foreach ($publishers as $publisher) {
            DB::table('books')
                ->where('publisher_id', $publisher->id)
                ->update(['publisher' => $publisher->name]);
        }
    }
};
