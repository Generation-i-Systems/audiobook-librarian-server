<?php

namespace Database\Seeders;

use App\Models\Genre;
use Illuminate\Database\Seeder;

class GenreSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $genres = [
            'Action',
            'Church',
            'Classic',
            'Computer',
            'Fantasy',
            'General Fiction',
            'Historical Fiction',
            'History',
            'Kids',
            'LitRPG',
            'Non Fiction',
            'Religion',
            'Romance',
            'Science',
            'Science Fiction',
            'Other',
        ];

        foreach ($genres as $genreName) {
            Genre::create(['name' => $genreName]);
        }
    }
}
