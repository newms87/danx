<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every column that names an `audit_request` row becomes a real, database-enforced foreign key,
 * so a deleted audit request can never leave a reference to itself behind (SG-858).
 *
 * ## The policy, per column
 *
 * An audit request is one unit of work: one web request, one queued job, one forked child. What
 * points at it is one of two things, and the database now treats them differently:
 *
 * | Column                              | ON DELETE | Why                                                      |
 * |-------------------------------------|-----------|----------------------------------------------------------|
 * | `audits.audit_request_id`           | CASCADE   | A model-change record made BY that unit of work. It has no meaning without it, and the column is NOT NULL. |
 * | `api_logs.audit_request_id`         | CASCADE   | An external call made BY that unit of work.              |
 * | `error_log_entry.audit_request_id`  | CASCADE   | One occurrence of an error, inside that unit of work. The deduplicated `error_logs` row it counts towards is not touched. |
 * | `job_dispatch.running_audit_request_id`  | SET NULL | A job dispatch is its own record, with its own lifecycle and listing; the audit request is only the context it ran in. |
 * | `job_dispatch.dispatch_audit_request_id` | SET NULL | Same record, the context that queued it.            |
 *
 * Not changed here, and why:
 *
 * - `audit_request.parent_id` already carries `audit_request_parent_id_foreign ... ON DELETE SET
 *   NULL` (migration 0014), and it stays SET NULL rather than becoming CASCADE. A child audit
 *   request is not a record ABOUT its parent: it is the live audit record of a DIFFERENT process
 *   (a queued job, a forked child). Cascading would delete a running process's own audit record
 *   from outside that process, where nothing can reset the pointer that process holds to it, and
 *   its next audit write would then fail. Nulling the link loses only the tree edge.
 * - A consuming application keys the audit request columns on its own tables itself (gpt-manager's
 *   `agent_dispatch_events.audit_request_id` already carries `ON DELETE SET NULL`).
 *
 * ## How it is applied to a large, live table
 *
 * Each key is added `NOT VALID` first. That enforces it for every write from that instant, and
 * takes only a momentary lock, because it does not read the existing rows. Rows that already
 * reference a missing audit request are then removed (or nulled) by the same policy, and the
 * key is finally `VALIDATE`d, which scans the table under a lock that does not block reads or
 * writes. Adding before cleaning closes the window in which a new orphan could appear between
 * the cleanup and the key.
 *
 * Postgres does not index a referencing column for you, and without one every audit-request
 * delete would scan the whole referencing table to find the rows to cascade or null. Each column
 * gets an index unless some valid index already leads with it (production carries several under
 * legacy names), built `CONCURRENTLY` so writes are never blocked. That is also why this
 * migration runs outside a transaction.
 *
 * The first auditing migration (0000) tried to add NO ACTION keys on the three child tables, but
 * swallowed any failure, so a database may or may not have them. They are dropped by name first
 * so every database ends with exactly the keys above.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /** @var array<string, array{table: string, column: string, onDelete: string}> constraint name => definition */
    private const array FOREIGN_KEYS = [
        'audits_audit_request_id_foreign'                => ['table' => 'audits', 'column' => 'audit_request_id', 'onDelete' => 'CASCADE'],
        'api_logs_audit_request_id_foreign'              => ['table' => 'api_logs', 'column' => 'audit_request_id', 'onDelete' => 'CASCADE'],
        'error_log_entry_audit_request_id_foreign'       => ['table' => 'error_log_entry', 'column' => 'audit_request_id', 'onDelete' => 'CASCADE'],
        'job_dispatch_running_audit_request_id_foreign'  => ['table' => 'job_dispatch', 'column' => 'running_audit_request_id', 'onDelete' => 'SET NULL'],
        'job_dispatch_dispatch_audit_request_id_foreign' => ['table' => 'job_dispatch', 'column' => 'dispatch_audit_request_id', 'onDelete' => 'SET NULL'],
    ];

    public function up(): void
    {
        foreach (self::FOREIGN_KEYS as $name => ['table' => $table, 'column' => $column]) {
            $this->ensureLeadingIndex($table, $column);
        }

        foreach (self::FOREIGN_KEYS as $name => ['table' => $table, 'column' => $column, 'onDelete' => $onDelete]) {
            DB::statement("ALTER TABLE \"$table\" DROP CONSTRAINT IF EXISTS \"$name\"");
            DB::statement("ALTER TABLE \"$table\" ADD CONSTRAINT \"$name\" FOREIGN KEY (\"$column\") REFERENCES audit_request (id) ON DELETE $onDelete NOT VALID");
        }

        foreach (self::FOREIGN_KEYS as $name => ['table' => $table, 'column' => $column, 'onDelete' => $onDelete]) {
            $orphans = "\"$column\" IS NOT NULL AND NOT EXISTS (SELECT 1 FROM audit_request ar WHERE ar.id = \"$table\".\"$column\")";

            if ($onDelete === 'CASCADE') {
                DB::statement("DELETE FROM \"$table\" WHERE $orphans");
            } else {
                DB::statement("UPDATE \"$table\" SET \"$column\" = NULL WHERE $orphans");
            }

            DB::statement("ALTER TABLE \"$table\" VALIDATE CONSTRAINT \"$name\"");
        }
    }

    public function down(): void
    {
        foreach (self::FOREIGN_KEYS as $name => ['table' => $table, 'column' => $column]) {
            DB::statement("ALTER TABLE \"$table\" DROP CONSTRAINT IF EXISTS \"$name\"");
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS "' . $this->indexName($table, $column) . '"');
        }
    }

    /**
     * Give $table.$column an index unless a valid one already leads with it. A leftover INVALID
     * index under our own name (an interrupted concurrent build) is dropped and rebuilt.
     */
    private function ensureLeadingIndex(string $table, string $column): void
    {
        $leading = DB::selectOne(
            'SELECT 1 FROM pg_index i
               JOIN pg_class t ON t.oid = i.indrelid
               JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = i.indkey[0]
              WHERE t.relname = ? AND a.attname = ? AND i.indisvalid',
            [$table, $column],
        );

        if ($leading) {
            return;
        }

        $index = $this->indexName($table, $column);
        DB::statement("DROP INDEX CONCURRENTLY IF EXISTS \"$index\"");
        DB::statement("CREATE INDEX CONCURRENTLY \"$index\" ON \"$table\" (\"$column\")");
    }

    private function indexName(string $table, string $column): string
    {
        return "{$table}_{$column}_index";
    }
};
