//Create tests for other resources
use App\Models\Genre;
use App\Models\Author;

class GenreTest extends TestCase
{
    use RefreshDatabase;

    public function test_genres_index_page()
    {
        $response = $this->get(route('admin.genres.index'));

        $response->assertStatus(200);
    }

    public function test_authors_index_page()
    {
        $response = $this->get(route('admin.authors.index'));

        $response->assertStatus(200);
    }
}
