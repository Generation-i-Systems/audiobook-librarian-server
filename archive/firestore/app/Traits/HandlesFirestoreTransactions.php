<?php

namespace App\Traits;

use Google\Cloud\Firestore\Transaction;
use Illuminate\Support\Facades\Log;

trait HandlesFirestoreTransactions
{
    /**
     * Run a Firestore transaction
     *
     * @param callable $callback The transaction callback
     * @param int $maxAttempts Maximum number of attempts
     * @return mixed The result of the transaction callback
     */
    protected function runInTransaction(callable $callback, int $maxAttempts = 5)
    {
        $attempts = 0;

        while ($attempts < $maxAttempts) {
            try {
                return $this->db->runTransaction(function (Transaction $transaction) use ($callback) {
                    return $callback($transaction);
                });
            } catch (\Exception $e) {
                $attempts++;

                if ($attempts >= $maxAttempts) {
                    Log::error("Firestore transaction failed after $maxAttempts attempts: " . $e->getMessage());
                    throw $e;
                }

                // Exponential backoff
                usleep(100000 * (2 ** ($attempts - 1)));
            }
        }

        return null;
    }

    /**
     * Create a new document in the specified collection
     *
     * @param string $collection The collection path
     * @param array $data The document data
     * @param string|null $documentId Optional document ID
     * @return string|null The document ID or null on failure
     */
    protected function createDocument(string $collection, array $data, ?string $documentId = null): ?string
    {
        try {
            $collectionRef = $this->db->collection($collection);

            if ($documentId) {
                $docRef = $collectionRef->document($documentId);
                $docRef->set($data);
                return $documentId;
            }

            $docRef = $collectionRef->newDocument();
            $docRef->set($data);
            return $docRef->id();
        } catch (\Throwable $e) {
            Log::error("Firestore createDocument failed for collection $collection: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Delete a document from the specified collection
     *
     * @param string $collection The collection path
     * @param string $documentId The document ID
     * @return bool True on success, false on failure
     */
    protected function deleteDocument(string $collection, string $documentId): bool
    {
        try {
            $docRef = $this->db->collection($collection)->document($documentId);
            $docRef->delete();
            return true;
        } catch (\Throwable $e) {
            Log::error("Firestore deleteDocument failed for $collection/$documentId: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update a document in the specified collection
     *
     * @param string $collection The collection path
     * @param string $documentId The document ID
     * @param array $data The data to update
     * @param bool $merge Whether to merge with existing data
     * @return bool True on success, false on failure
     */
    public function updateDocument(string $collection, string $documentId, array $data, bool $merge = true): bool
    {
        try {
            $docRef = $this->db->collection($collection)->document($documentId);
            $docRef->set($data, ['merge' => $merge]);
            return true;
        } catch (\Throwable $e) {
            Log::error("Firestore updateDocument failed for $collection/$documentId: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get a document from the specified collection
     *
     * @param string $collection The collection path
     * @param string $documentId The document ID
     * @return array|null The document data or null if not found
     */
    public function getDocument(string $collection, string $documentId): ?array
    {
        try {
            $docRef = $this->db->collection($collection)->document($documentId);
            $snapshot = $docRef->snapshot();

            if ($snapshot->exists()) {
                $data = $snapshot->data();
                $data['id'] = $snapshot->id();
                return $data;
            }

            return null;
        } catch (\Throwable $e) {
            Log::error("Firestore getDocument failed for $collection/$documentId: " . $e->getMessage());
            return null;
        }
    }
}
