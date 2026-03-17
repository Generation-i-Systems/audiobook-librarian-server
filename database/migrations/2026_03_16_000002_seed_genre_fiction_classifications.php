<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    public function up(): void
    {
        $fiction = [
            'Fantasy', 'Science Fiction', 'Sci-Fi', 'Horror', 'Mystery', 'Thriller',
            'Romance', 'Literary Fiction', 'Historical Fiction', 'Adventure', 'Dystopian',
            'Urban Fantasy', 'Paranormal', 'Young Adult', "Children's", 'Fairy Tales',
            'Mythology', 'Action', 'Crime Fiction', 'Suspense', 'Magical Realism',
            'Graphic Novel', 'Comedy', 'Satire', 'Drama', 'Western', 'Gothic',
        ];

        $nonfiction = [
            'History', 'Biography', 'Autobiography', 'Memoir', 'Self-Help', 'Business',
            'Science', 'Technology', 'Philosophy', 'Religion', 'Spirituality', 'True Crime',
            'Politics', 'Travel', 'Nature', 'Health', 'Psychology', 'Economics',
            'Education', 'Reference', 'Cooking', 'Art', 'Music', 'Sports', 'Finance',
            'Law', 'Medicine', 'Environment', 'Current Events', 'Social Science',
            'Anthropology', 'Archaeology', 'Mathematics',
        ];

        foreach ($fiction as $name) {
            DB::table('genres')->where('name', $name)->update(['is_fiction' => true]);
        }

        foreach ($nonfiction as $name) {
            DB::table('genres')->where('name', $name)->update(['is_fiction' => false]);
        }
    }

    public function down(): void
    {
        DB::table('genres')->whereNotNull('is_fiction')->update(['is_fiction' => null]);
    }
};
