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
 * points at it is one of two things, and the database treats them differently:
 *
 * | Column                                   | ON DELETE | Why                                                  |
 * |------------------------------------------|-----------|------------------------------------------------------|
 * | `audits.audit_request_id`                | CASCADE   | A model-change record made BY that unit of work. It has no meaning without it, and the column is NOT NULL. |
 * | `api_logs.audit_request_id`              | CASCADE   | An external call made BY that unit of work.          |
 * | `error_log_entry.audit_request_id`       | CASCADE   | One occurrence of an error inside that unit of work. The deduplicated `error_logs` row it counts towards is not touched. |
 * | `audit_request.parent_id`                | CASCADE   | A child audit request (a job it dispatched, a process it forked) belongs to its parent's trail: deleting a trail deletes all of it. Replaces the SET NULL key migration 0014 added. |
 * | `job_dispatch.running_audit_request_id`  | SET NULL  | A job dispatch is its own record, with its own lifecycle and listing; the audit request is only the context it ran in. |
 * | `job_dispatch.dispatch_audit_request_id` | SET NULL  | Same record, the context that queued it.             |
 *
 * Columns on a consuming application's own tables are that application's to key.
 *
 * ## How it is applied to a large, live table
 *
 * Locks, in order, per key:
 *
 * - `DROP CONSTRAINT IF EXISTS` takes ACCESS EXCLUSIVE on the referencing table (every read and
 *   write waits), and `ADD ... NOT VALID` takes SHARE ROW EXCLUSIVE on both the referencing
 *   table and `audit_request` (writes wait). Neither reads existing rows, so each is held for
 *   milliseconds, but acquiring either one queues every later query on that table behind any
 *   transaction already open on it. `lock_timeout` bounds that wait: rather than stall live
 *   traffic, the statement fails and the migration fails. Every step is idempotent, so the next
 *   `migrate` simply runs it again.
 * - Rows that already reference a missing audit request are removed (or nulled) by the same
 *   policy. The key is already enforced for new writes, so no new orphan can appear meanwhile.
 * - `VALIDATE CONSTRAINT` scans the table under SHARE UPDATE EXCLUSIVE, which blocks neither
 *   reads nor writes.
 *
 * Postgres does not index a referencing column for you, and without one every audit-request
 * delete would scan the whole referencing table to find the rows to cascade or null. Each column
 * gets an index unless some valid index already leads with it, built `CONCURRENTLY` so writes
 * are never blocked. That is also why this migration runs outside a transaction. A concurrent
 * build that times out leaves an INVALID index behind; the next run drops and rebuilds it.
 *
 * The first auditing migration (0000) tried to add NO ACTION keys on the three child tables but
 * swallowed any failure, so a database may or may not have them. They are dropped by name first
 * so every database ends with exactly the keys above.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const string LOCK_TIMEOUT = '5s';

    /**
     * constraint name => the key this migration creates, and the key it replaces (restored by
     * down()): `null` where no earlier migration declared one.
     *
     * @var array<string, array{table: string, column: string, onDelete: string, replaces: ?string}>
     */
    private const array FOREIGN_KEYS = [
        'audits_audit_request_id_foreign'                => ['table' => 'audits', 'column' => 'audit_request_id', 'onDelete' => 'CASCADE', 'replaces' => 'NO ACTION'],
        'api_logs_audit_request_id_foreign'              => ['table' => 'api_logs', 'column' => 'audit_request_id', 'onDelete' => 'CASCADE', 'replaces' => 'NO ACTION'],
        'error_log_entry_audit_request_id_foreign'       => ['table' => 'error_log_entry', 'column' => 'audit_request_id', 'onDelete' => 'CASCADE', 'replaces' => 'NO ACTION'],
        'audit_request_parent_id_foreign'                => ['table' => 'audit_request', 'column' => 'parent_id', 'onDelete' => 'CASCADE', 'replaces' => 'SET NULL'],
        'job_dispatch_running_audit_request_id_foreign'  => ['table' => 'job_dispatch', 'column' => 'running_audit_request_id', 'onDelete' => 'SET NULL', 'replaces' => null],
        'job_dispatch_dispatch_audit_request_id_foreign' => ['table' => 'job_dispatch', 'column' => 'dispatch_audit_request_id', 'onDelete' => 'SET NULL', 'replaces' => null],
    ];

    /**
     * Indexes this migration may create and down() may therefore drop. `parent_id` is indexed by
     * migration 0014, and a consuming application may own an index on
     * `running_audit_request_id` under Laravel's default name; neither is this migration's.
     */
    private const array OWNED_INDEXES = [
        'audits_audit_request_id_index',
        'api_logs_audit_request_id_index',
        'error_log_entry_audit_request_id_index',
        'job_dispatch_dispatch_audit_request_id_index',
    ];

    public function up(): void
    {
        $this->withLockTimeout(function (): void {
            foreach (self::FOREIGN_KEYS as ['table' => $table, 'column' => $column]) {
                $this->ensureLeadingIndex($table, $column);
            }

            foreach (self::FOREIGN_KEYS as $name => ['table' => $table, 'column' => $column, 'onDelete' => $onDelete]) {
                $this->replaceForeignKey($name, $table, $column, $onDelete, validate: false);
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
        });
    }

    public function down(): void
    {
        $this->withLockTimeout(function (): void {
            foreach (self::FOREIGN_KEYS as $name => ['table' => $table, 'column' => $column, 'replaces' => $replaces]) {
                if ($replaces === null) {
                    DB::statement("ALTER TABLE \"$table\" DROP CONSTRAINT IF EXISTS \"$name\"");
                } else {
                    $this->replaceForeignKey($name, $table, $column, $replaces, validate: true);
                }
            }

            foreach (self::OWNED_INDEXES as $index) {
                DB::statement("DROP INDEX CONCURRENTLY IF EXISTS \"$index\"");
            }
        });
    }

    /** Runs $steps with `lock_timeout` set on this session, and restores the session default after. */
    private function withLockTimeout(Closure $steps): void
    {
        DB::statement("SET lock_timeout = '" . self::LOCK_TIMEOUT . "'");

        try {
            $steps();
        } finally {
            DB::statement('RESET lock_timeout');
        }
    }

    private function replaceForeignKey(string $name, string $table, string $column, string $onDelete, bool $validate): void
    {
        DB::statement("ALTER TABLE \"$table\" DROP CONSTRAINT IF EXISTS \"$name\"");
        DB::statement(
            "ALTER TABLE \"$table\" ADD CONSTRAINT \"$name\" FOREIGN KEY (\"$column\") REFERENCES audit_request (id) ON DELETE $onDelete"
            . ($validate ? '' : ' NOT VALID'),
        );
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

        $index = "{$table}_{$column}_index";
        DB::statement("DROP INDEX CONCURRENTLY IF EXISTS \"$index\"");
        DB::statement("CREATE INDEX CONCURRENTLY \"$index\" ON \"$table\" (\"$column\")");
    }
};
