<?php

namespace GregPriday\LaravelRetry\DeadLetterQueue;

interface DeadLetterQueueStorage
{
    /**
     * Store a dead letter.
     *
     * @param  array  $data  Dead letter data
     * @return string|int ID of the stored entry
     */
    public function store(array $data): string|int;

    /**
     * Retrieve dead letters from storage.
     *
     * @param  int  $limit  Maximum number of items to retrieve
     * @param  array  $filters  Optional filters to apply
     * @return array<string|int, array> Associative array of dead letters with IDs as keys
     */
    public function retrieve(int $limit = 100, array $filters = []): array;

    /**
     * Mark a dead letter as processed.
     *
     * @param  string|int  $id  ID of the dead letter
     * @param  mixed  $result  Result of processing
     * @return bool Whether the operation was successful
     */
    public function markAsProcessed(string|int $id, mixed $result): bool;

    /**
     * Mark a dead letter as failed to process.
     *
     * @param  string|int  $id  ID of the dead letter
     * @param  string  $error  Error message
     * @return bool Whether the operation was successful
     */
    public function markAsFailed(string|int $id, string $error): bool;

    /**
     * Delete a dead letter.
     *
     * @param  string|int  $id  ID of the dead letter
     * @return bool Whether the operation was successful
     */
    public function delete(string|int $id): bool;

    /**
     * Clear all dead letters.
     *
     * @param  array  $filters  Optional filters to apply
     * @return int Number of deleted entries
     */
    public function clear(array $filters = []): int;

    /**
     * Count dead letters.
     *
     * @param  array  $filters  Optional filters to apply
     * @return int Number of dead letters
     */
    public function count(array $filters = []): int;
}
