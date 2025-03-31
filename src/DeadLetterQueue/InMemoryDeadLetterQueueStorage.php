<?php

namespace GregPriday\LaravelRetry\DeadLetterQueue;

class InMemoryDeadLetterQueueStorage implements DeadLetterQueueStorage
{
    /**
     * Stored dead letters.
     *
     * @var array<string|int, array>
     */
    protected array $storage = [];

    /**
     * Auto-incrementing ID counter.
     */
    protected int $idCounter = 1;

    /**
     * Store a dead letter in memory.
     *
     * @param  array  $data  Dead letter data
     * @return string|int ID of the stored entry
     */
    public function store(array $data): string|int
    {
        $id = $this->idCounter++;

        $this->storage[$id] = [
            'id'                => $id,
            'operation'         => $data['operation'] ?? '',
            'error_message'     => $data['error_message'] ?? '',
            'error_class'       => $data['error_class'] ?? '',
            'error_trace'       => $data['error_trace'] ?? '',
            'exception_history' => $data['exception_history'] ?? [],
            'context'           => $data['context'] ?? [],
            'status'            => 'pending',
            'created_at'        => $data['created_at'] ?? now(),
            'processed_at'      => null,
            'processing_result' => null,
            'processing_error'  => null,
        ];

        return $id;
    }

    /**
     * Retrieve dead letters from memory.
     *
     * @param  int  $limit  Maximum number of items to retrieve
     * @param  array  $filters  Optional filters to apply
     * @return array<string|int, array> Associative array of dead letters with IDs as keys
     */
    public function retrieve(int $limit = 100, array $filters = []): array
    {
        $results = $this->storage;

        // Apply filters
        if (! empty($filters)) {
            $results = array_filter($results, function ($item) use ($filters) {
                // Apply status filter if provided
                if (isset($filters['status']) && $item['status'] !== $filters['status']) {
                    return false;
                }

                // Apply date filters if provided
                if (isset($filters['created_before'])) {
                    $beforeDate = is_string($filters['created_before'])
                        ? strtotime($filters['created_before'])
                        : $filters['created_before'];

                    $itemDate = is_string($item['created_at'])
                        ? strtotime($item['created_at'])
                        : $item['created_at'];

                    if ($itemDate >= $beforeDate) {
                        return false;
                    }
                }

                if (isset($filters['created_after'])) {
                    $afterDate = is_string($filters['created_after'])
                        ? strtotime($filters['created_after'])
                        : $filters['created_after'];

                    $itemDate = is_string($item['created_at'])
                        ? strtotime($item['created_at'])
                        : $item['created_at'];

                    if ($itemDate <= $afterDate) {
                        return false;
                    }
                }

                // Apply operation filter if provided
                if (isset($filters['operation']) && $item['operation'] !== $filters['operation']) {
                    return false;
                }

                return true;
            });
        }

        // Sort by created_at descending
        usort($results, function ($a, $b) {
            $timeA = is_string($a['created_at']) ? strtotime($a['created_at']) : $a['created_at'];
            $timeB = is_string($b['created_at']) ? strtotime($b['created_at']) : $b['created_at'];

            return $timeB <=> $timeA;
        });

        // Apply limit
        return array_slice($results, 0, $limit, true);
    }

    /**
     * Mark a dead letter as processed.
     *
     * @param  string|int  $id  ID of the dead letter
     * @param  mixed  $result  Result of processing
     * @return bool Whether the operation was successful
     */
    public function markAsProcessed(string|int $id, mixed $result): bool
    {
        if (! isset($this->storage[$id])) {
            return false;
        }

        $this->storage[$id]['status'] = 'processed';
        $this->storage[$id]['processed_at'] = now();
        $this->storage[$id]['processing_result'] = $result;
        $this->storage[$id]['processing_error'] = null;

        return true;
    }

    /**
     * Mark a dead letter as failed to process.
     *
     * @param  string|int  $id  ID of the dead letter
     * @param  string  $error  Error message
     * @return bool Whether the operation was successful
     */
    public function markAsFailed(string|int $id, string $error): bool
    {
        if (! isset($this->storage[$id])) {
            return false;
        }

        $this->storage[$id]['status'] = 'failed';
        $this->storage[$id]['processed_at'] = now();
        $this->storage[$id]['processing_result'] = null;
        $this->storage[$id]['processing_error'] = $error;

        return true;
    }

    /**
     * Delete a dead letter.
     *
     * @param  string|int  $id  ID of the dead letter
     * @return bool Whether the operation was successful
     */
    public function delete(string|int $id): bool
    {
        if (! isset($this->storage[$id])) {
            return false;
        }

        unset($this->storage[$id]);

        return true;
    }

    /**
     * Clear all dead letters.
     *
     * @param  array  $filters  Optional filters to apply
     * @return int Number of deleted entries
     */
    public function clear(array $filters = []): int
    {
        if (empty($filters)) {
            $count = count($this->storage);
            $this->storage = [];

            return $count;
        }

        $itemsToDelete = $this->retrieve(PHP_INT_MAX, $filters);
        $count = count($itemsToDelete);

        foreach (array_keys($itemsToDelete) as $id) {
            unset($this->storage[$id]);
        }

        return $count;
    }

    /**
     * Count dead letters.
     *
     * @param  array  $filters  Optional filters to apply
     * @return int Number of dead letters
     */
    public function count(array $filters = []): int
    {
        return count($this->retrieve(PHP_INT_MAX, $filters));
    }

    /**
     * Get all stored dead letters (for testing).
     *
     * @return array<string|int, array>
     */
    public function getAll(): array
    {
        return $this->storage;
    }
}
