<?php

namespace Tests\Browser;

use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Support\Facades\Hash;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class FirebaseAuthTest extends DuskTestCase
{
    protected $documentStore;

    protected $usersCollection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->firestore = new FirestoreClient([
            'projectId' => config('firebase.project_id'),
            'keyFilePath' => config('firebase.credentials.file'),
        ]);

        $this->usersCollection = $this->firestore->collection('users');
        $this->clearTestData();
    }

    protected function tearDown(): void
    {
        $this->clearTestData();
        parent::tearDown();
    }

    protected function clearTestData(): void
    {
        $users = $this->usersCollection->where('email', 'in', [
            'test@example.com',
            'unverified@example.com',
        ])->documents();

        foreach ($users as $user) {
            $user->reference->delete();
        }
    }

    protected function createTestUser(): string
    {
        $docRef = $this->usersCollection->add([
            'name' => 'Test User',
            'username' => 'testuser',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'role' => 'user',
            'email_verified_at' => new \DateTime(),
            'created_at' => new \DateTime(),
            'updated_at' => new \DateTime(),
        ]);

        return $docRef->id();
    }

    /**
     * Test user can login with valid credentials.
     */
    public function testUserCanLogin(): void
    {
        $this->createTestUser();

        $this->browse(function (Browser $browser) {
            $browser->visit('/login')
                ->type('email', 'test@example.com')
                ->type('password', 'password')
                ->press('Log in')
                ->assertPathIs('/home');
        });
    }

    /**
     * Test unverified user cannot login.
     */
    public function testUnverifiedUserCannotLogin(): void
    {
        $this->usersCollection->add([
            'name' => 'Unverified User',
            'username' => 'unverified',
            'email' => 'unverified@example.com',
            'password' => Hash::make('password'),
            'role' => 'unverified',
            'created_at' => new \DateTime(),
            'updated_at' => new \DateTime(),
        ]);

        $this->browse(function (Browser $browser) {
            $browser->visit('/login')
                ->type('email', 'unverified@example.com')
                ->type('password', 'password')
                ->press('Log in')
                ->assertPathIs('/login')
                ->assertSee('Your account is pending admin approval');
        });
    }
}
