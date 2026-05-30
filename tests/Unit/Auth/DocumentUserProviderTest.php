<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use App\Auth\DocumentUserProvider;
use App\Contracts\DocumentStoreServiceInterface;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class DocumentUserProviderTest extends TestCase
{
    public function testRehashPasswordLoggingDoesNotIncludeCredentials(): void
    {
        $log = Log::spy();

        $user = new User([
            'id' => 123,
            'email' => 'user@example.com',
            'password' => Hash::make('existing-password'),
        ]);

        $provider = new DocumentUserProvider(Mockery::mock(DocumentStoreServiceInterface::class));
        $provider->rehashPasswordIfRequired($user, ['password' => 'plain-secret'], false);

        $log->shouldHaveReceived('debug')->with(
            'DocumentUserProvider::rehashPasswordIfRequired called',
            Mockery::on(function (array $context): bool {
                $encodedContext = json_encode($context, JSON_THROW_ON_ERROR);

                return ! str_contains($encodedContext, 'plain-secret')
                    && ! array_key_exists('credentials', $context)
                    && $context['has_password'] === true;
            })
        );
    }
}
