<?php

namespace Newms87\Danx\Services\Debug;

use Illuminate\Console\Command;
use Newms87\Danx\Models\Audit\Audit;
use Newms87\Danx\Models\Audit\AuditRequest;
use Newms87\Danx\Services\Debug\Concerns\DebugOutputHelper;

/**
 * Handles ORM audit records (model changes tracked by laravel-auditing) for debugging.
 *
 * Displays model changes associated with an audit request, including created,
 * updated, and deleted events with diff views of old vs new values.
 */
class AuditRecordDebugService
{
    use DebugOutputHelper;

    private const int VALUE_TRUNCATE_LENGTH = 100;

    private const int MAX_INLINE_FIELDS = 3;

    /**
     * List audit records for an audit request with optional filtering.
     *
     * Filters supported:
     * - 'type' => LIKE match on auditable_type (e.g., "User" matches "App\Models\User")
     * - 'event' => exact match on event (created, updated, deleted, restored)
     */
    public function listAuditRecords(AuditRequest $auditRequest, array $filters, Command $command, bool $json = false): void
    {
        $query = $auditRequest->audits();

        // Apply filters
        if (!empty($filters['type'])) {
            $query->where('auditable_type', 'LIKE', '%' . $filters['type'] . '%');
        }

        if (!empty($filters['event'])) {
            $query->where('event', $filters['event']);
        }

        $audits = $query->orderBy('created_at')->get();

        if ($json) {
            $this->outputJsonList($audits, $command);

            return;
        }

        $this->showHeader("Model Changes for Audit Request #{$auditRequest->id}", $command);

        if ($audits->isEmpty()) {
            $command->line('No model changes found matching the filters.');

            return;
        }

        $this->renderAuditsTable($audits, $command);
        $this->showAuditsSummary($audits, $command);

        $command->newLine();
        $command->comment('Use --audit-id=ID for full change details');
    }

    /**
     * Show detailed information for a specific audit record.
     */
    public function showAuditRecordDetail(int $auditId, Command $command): void
    {
        $audit = Audit::with('user')->find($auditId);

        if (!$audit) {
            $command->error("Audit Record #{$auditId} not found.");

            return;
        }

        $this->showHeader("Audit Record #{$audit->id}", $command);

        // Event (colorized)
        $command->line('Event: ' . $this->colorizeEvent($audit->event));

        // Model info
        $command->line("Model: {$audit->auditable_type}");
        $command->line("Model ID: {$audit->auditable_id}");

        // User info (if available)
        if ($audit->user) {
            $command->line("User: {$audit->user->email} (ID: {$audit->user_id})");
        } elseif ($audit->user_id) {
            $command->line("User ID: {$audit->user_id}");
        }

        // Timestamp
        $command->line("Created: {$this->formatTimestamp($audit->created_at)}");

        // Tags (if present)
        if (!empty($audit->tags)) {
            $command->line("Tags: {$audit->tags}");
        }

        $command->newLine();

        // Changes section
        $this->showSubHeader('Changes', $command);

        $oldValues = $audit->old_values ?? [];
        $newValues = $audit->new_values ?? [];

        $diffOutput = $this->formatValueDiff($oldValues, $newValues, $audit->event);
        $command->line($diffOutput);
    }

    /**
     * Creates a diff-style view of changes.
     *
     * For 'created' event: show all new_values as "+ field: value"
     * For 'deleted' event: show all old_values as "- field: value"
     * For 'updated' event: show both old and new values
     */
    public function formatValueDiff(array $oldValues, array $newValues, string $event = 'updated'): string
    {
        $lines = [];

        if ($event === 'created') {
            // Created: show all new values with +
            if (empty($newValues)) {
                return '    (no values recorded)';
            }

            foreach ($newValues as $field => $value) {
                $formattedValue = $this->formatValue($value);
                $lines[]        = "    <fg=green>+ {$field}: {$formattedValue}</>";
            }
        } elseif ($event === 'deleted') {
            // Deleted: show all old values with -
            if (empty($oldValues)) {
                return '    (no values recorded)';
            }

            foreach ($oldValues as $field => $value) {
                $formattedValue = $this->formatValue($value);
                $lines[]        = "    <fg=red>- {$field}: {$formattedValue}</>";
            }
        } else {
            // Updated/Restored: show diff
            $allFields = array_unique(array_merge(array_keys($oldValues), array_keys($newValues)));

            if (empty($allFields)) {
                return '    (no changes recorded)';
            }

            sort($allFields);

            foreach ($allFields as $field) {
                $oldValue = $oldValues[$field] ?? null;
                $newValue = $newValues[$field] ?? null;

                $lines[]  = "    {$field}:";
                $lines[]  = '      <fg=red>- ' . $this->formatValue($oldValue) . '</>';
                $lines[]  = '      <fg=green>+ ' . $this->formatValue($newValue) . '</>';
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Returns colorized event name.
     *
     * 'created' => green, 'updated' => yellow, 'deleted' => red, 'restored' => cyan
     */
    public function colorizeEvent(string $event): string
    {
        return match (strtolower($event)) {
            'created'  => "<fg=green>{$event}</>",
            'updated'  => "<fg=yellow>{$event}</>",
            'deleted'  => "<fg=red>{$event}</>",
            'restored' => "<fg=cyan>{$event}</>",
            default    => $event,
        };
    }

    /**
     * Format a value for display, handling arrays/objects and truncation.
     */
    private function formatValue(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            $jsonValue = json_encode($value);

            return $this->truncate($jsonValue, self::VALUE_TRUNCATE_LENGTH);
        }

        if (is_object($value)) {
            $jsonValue = json_encode($value);

            return $this->truncate($jsonValue, self::VALUE_TRUNCATE_LENGTH);
        }

        return $this->truncate((string)$value, self::VALUE_TRUNCATE_LENGTH);
    }

    /**
     * Get a description of fields changed for table display.
     */
    private function getFieldsChangedDescription(Audit $audit): string
    {
        $oldValues = $audit->old_values ?? [];
        $newValues = $audit->new_values ?? [];

        $allFields = array_unique(array_merge(array_keys($oldValues), array_keys($newValues)));
        $count     = count($allFields);

        if ($count === 0) {
            return '-';
        }

        if ($count <= self::MAX_INLINE_FIELDS) {
            return implode(', ', $allFields);
        }

        $firstFields    = array_slice($allFields, 0, self::MAX_INLINE_FIELDS);
        $remainingCount = $count - self::MAX_INLINE_FIELDS;

        return implode(', ', $firstFields) . " (+{$remainingCount})";
    }

    /**
     * Render audit records as a table.
     */
    private function renderAuditsTable($audits, Command $command): void
    {
        $rows = $audits->map(fn(Audit $audit) => [
            $audit->id,
            $this->colorizeEvent($audit->event),
            class_basename($audit->auditable_type),
            $audit->auditable_id,
            $this->getFieldsChangedDescription($audit),
            $this->formatTimestamp($audit->created_at),
        ])->toArray();

        $command->table(
            ['ID', 'Event', 'Model Type', 'Model ID', 'Fields Changed', 'Created'],
            $rows
        );
    }

    /**
     * Show summary statistics for audit records.
     */
    private function showAuditsSummary($audits, Command $command): void
    {
        $total    = $audits->count();
        $created  = $audits->filter(fn(Audit $audit) => strtolower($audit->event) === 'created')->count();
        $updated  = $audits->filter(fn(Audit $audit) => strtolower($audit->event) === 'updated')->count();
        $deleted  = $audits->filter(fn(Audit $audit) => strtolower($audit->event) === 'deleted')->count();
        $restored = $audits->filter(fn(Audit $audit) => strtolower($audit->event) === 'restored')->count();

        $parts = ["{$total} model changes"];

        if ($created > 0) {
            $parts[] = "{$created} created";
        }
        if ($updated > 0) {
            $parts[] = "{$updated} updated";
        }
        if ($deleted > 0) {
            $parts[] = "{$deleted} deleted";
        }
        if ($restored > 0) {
            $parts[] = "{$restored} restored";
        }

        $command->line('Total: ' . implode(', ', $parts));
    }

    /**
     * Output audit records as JSON.
     */
    private function outputJsonList($audits, Command $command): void
    {
        $data = $audits->map(fn(Audit $audit) => [
            'id'             => $audit->id,
            'event'          => $audit->event,
            'auditable_type' => $audit->auditable_type,
            'auditable_id'   => $audit->auditable_id,
            'user_id'        => $audit->user_id,
            'old_values'     => $audit->old_values,
            'new_values'     => $audit->new_values,
            'tags'           => $audit->tags,
            'created_at'     => $audit->created_at?->toIso8601String(),
        ])->toArray();

        $command->line(json_encode($data, JSON_PRETTY_PRINT));
    }
}
