<?php

namespace GregPriday\LaravelRetry\DeadLetterQueue;

use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DatabaseDeadLetterQueueStorage implements DeadLetterQueueStorage
{
    /**
     * The database table used for storing dead letters.
     */
    protected string $table;

    /**
     * The database connection to use.
     */
    protected ?string $connection;

    /**
     * Create a new database dead letter queue storage.
     *
     * @param  string|null  $table  Table name (defaults to 'retry_dead_letters')
     * @param  string|null  $connection  Database connection name
     */
    public function __construct(?string $table = null, ?string $connection = null)
    {
        $this->table = $table ?? config('retry.dead_letter.table', 'retry_dead_letters');
        $this->connection = $connection ?? config('retry.dead_letter.connection');

        $this->ensureTableExists();
    }

    /**
     * Store a dead letter in the database.
     *
     * @param  array  $data  Dead letter data
     * @return string|int ID of the stored entry
     */
    public function store(array $data): string|int
    {
        return DB::connection($this->connection)
            ->table($this->table)
            ->insertGetId([
                'operation'         => $data['operation'] ?? '',
                'error_message'     => $data['error_message'] ?? '',
                'error_class'       => $data['error_class'] ?? '',
                'error_trace'       => $data['error_trace'] ?? '',
                'exception_history' => json_encode($data['exception_history'] ?? []),
                'context'           => json_encode($data['context'] ?? []),
                'status'            => 'pending',
                'created_at'        => $data['created_at'] ?? now(),
                'processed_at'      => null,
                'processing_result' => null,
                'processing_error'  => null,
            ]);
    }

    /**
     * Retrieve dead letters from the database.
     *
     * @param  int  $limit  Maximum number of items to retrieve
     * @param  array  $filters  Optional filters to apply
     * @return array<string|int, array> Associative array of dead letters with IDs as keys
     */
    public function retrieve(int $limit = 100, array $filters = []): array
    {
        $query = DB::connection($this->connection)
            ->table($this->table);

        // Apply status filter if provided
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // Apply date filters if provided
        if (isset($filters['created_before'])) {
            $query->where('created_at', '<', $filters['created_before']);
        }

        if (isset($filters['created_after'])) {
            $query->where('created_at', '>', $filters['created_after']);
        }

        // Apply operation filter if provided
        if (isset($filters['operation'])) {
            $query->where('operation', $filters['operation']);
        }

        // Limit the number of results
        $results = $query->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        $deadLetters = [];

        foreach ($results as $result) {
            $deadLetters[$result->id] = [
                'id'                => $result->id,
                'operation'         => $result->operation,
                'error_message'     => $result->error_message,
                'error_class'       => $result->error_class,
                'error_trace'       => $result->error_trace,
                'exception_history' => json_decode($result->exception_history, true) ?? [],
                'context'           => json_decode($result->context, true) ?? [],
                'status'            => $result->status,
                'created_at'        => $result->created_at,
                'processed_at'      => $result->processed_at,
                'processing_result' => json_decode($result->processing_result, true),
                'processing_error'  => $result->processing_error,
            ];
        }

        return $deadLetters;
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
        try {
            $updated = DB::connection($this->connection)
                ->table($this->table)
                ->where('id', $id)
                ->update([
                    'status'            => 'processed',
                    'processed_at'      => now(),
                    'processing_result' => json_encode($result),
                    'processing_error'  => null,
                ]);

            return $updated > 0;
        } catch (Exception $e) {
            return false;
        }
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
        try {
            $updated = DB::connection($this->connection)
                ->table($this->table)
                ->where('id', $id)
                ->update([
                    'status'            => 'failed',
                    'processed_at'      => now(),
                    'processing_result' => null,
                    'processing_error'  => $error,
                ]);

            return $updated > 0;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Delete a dead letter.
     *
     * @param  string|int  $id  ID of the dead letter
     * @return bool Whether the operation was successful
     */
    public function delete(string|int $id): bool
    {
        try {
            $deleted = DB::connection($this->connection)
                ->table($this->table)
                ->where('id', $id)
                ->delete();

            return $deleted > 0;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Clear all dead letters.
     *
     * @param  array  $filters  Optional filters to apply
     * @return int Number of deleted entries
     */
    public function clear(array $filters = []): int
    {
        $query = DB::connection($this->connection)
            ->table($this->table);

        // Apply status filter if provided
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // Apply date filters if provided
        if (isset($filters['created_before'])) {
            $query->where('created_at', '<', $filters['created_before']);
        }

        if (isset($filters['created_after'])) {
            $query->where('created_at', '>', $filters['created_after']);
        }

        // Apply operation filter if provided
        if (isset($filters['operation'])) {
            $query->where('operation', $filters['operation']);
        }

        return $query->delete();
    }

    /**
     * Count dead letters.
     *
     * @param  array  $filters  Optional filters to apply
     * @return int Number of dead letters
     */
    public function count(array $filters = []): int
    {
        $query = DB::connection($this->connection)
            ->table($this->table);

        // Apply status filter if provided
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // Apply date filters if provided
        if (isset($filters['created_before'])) {
            $query->where('created_at', '<', $filters['created_before']);
        }

        if (isset($filters['created_after'])) {
            $query->where('created_at', '>', $filters['created_after']);
        }

        // Apply operation filter if provided
        if (isset($filters['operation'])) {
            $query->where('operation', $filters['operation']);
        }

        return $query->count();
    }

    /**
     * Ensure the dead letter queue table exists.
     */
    protected function ensureTableExists(): void
    {
        $connection = $this->connection;

        if (! Schema::connection($connection)->hasTable($this->table)) {
            Schema::connection($connection)->create($this->table, function ($table) {
                $table->id();
                $table->string('operation')->nullable();
                $table->string('error_message')->nullable();
                $table->string('error_class')->nullable();
                $table->text('error_trace')->nullable();
                $table->json('exception_history')->nullable();
                $table->json('context')->nullable();
                $table->string('status')->default('pending');
                $table->timestamp('created_at')->nullable();
                $table->timestamp('processed_at')->nullable();
                $table->json('processing_result')->nullable();
                $table->text('processing_error')->nullable();

                $table->index('status');
                $table->index('created_at');
                $table->index('operation');
            });
        }
    }
}
