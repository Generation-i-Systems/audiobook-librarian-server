<?php

namespace App\Facades;

use App\Contracts\BookServiceInterface;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;

/**
 * @method static BookServiceInterface[] all()
 * @method static BookServiceInterface|null get(string $serviceName)
 * @method static BookServiceInterface firstAvailable()
 * @method static array search(string $query, array $options = [])
 * @method static array|null getBookDetails(string $id, string $serviceName = null)
 */
class BookService extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'book.services';
    }

    /**
     * Get all registered book services
     */
    public static function all(): array
    {
        $services = app('book.services');
        $result = [];

        foreach ($services as $name => $service) {
            $result[$name] = $service;
        }

        return $result;
    }

    /**
     * Get a specific book service by name
     */
    public static function get(string $serviceName): ?BookServiceInterface
    {
        $services = static::all();

        return $services[$serviceName] ?? null;
    }

    /**
     * Get the first available book service
     */
    public static function firstAvailable(): ?BookServiceInterface
    {
        $services = static::all();

        return reset($services) ?: null;
    }

    /**
     * Search across all available book services
     */
    public static function search(string $query, array $options = []): array
    {
        $results = [];
        $services = static::all();

        foreach ($services as $serviceName => $service) {
            try {
                if ($serviceResults = $service->searchBooks($query, $options)) {
                    $results[$serviceName] = $serviceResults;
                }
            } catch (\Exception $e) {
                // Log error but continue with other services
                Log::error("Search failed for service {$serviceName}", [
                    'error' => $e->getMessage(),
                    'query' => $query,
                ]);
            }
        }

        return $results;
    }

    /**
     * Get book details from a specific service or try all services
     */
    public static function getBookDetails(string $id, ?string $serviceName = null): ?array
    {
        if ($serviceName) {
            if ($service = static::get($serviceName)) {
                return $service->getBookDetails($id);
            }

            return null;
        }

        // Try all services until we get a result
        foreach (static::all() as $service) {
            try {
                if ($result = $service->getBookDetails($id)) {
                    return $result;
                }
            } catch (\Exception $e) {
                // Log error but continue with other services
                Log::error('Failed to get book details from service', [
                    'error' => $e->getMessage(),
                    'id' => $id,
                ]);
            }
        }

        return null;
    }
}
