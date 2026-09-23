<?php

namespace Newms87\Danx\Resources;

use DateTimeInterface;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Newms87\Danx\Contracts\HasRecordDeletedAt;

abstract class ActionResource
{
    public static bool $withTypedData = true;

    public static string $type          = '';

    public static function typedData(Model $model, array $responseData = []): array
    {
        if (!static::$withTypedData) {
            return $responseData;
        }

        $type = static::$type ?: basename(preg_replace('#\\\\#', '/', static::class));

        return [
            'id'           => $model->getKey(),
            '__type'       => $type,
            '__timestamp'  => $model->updated_at?->getPreciseTimestamp(3) ?: microtime(true),
            '__deleted_at' => static::recordDeletedAtFor($model),
        ] + $responseData;
    }

    /**
     * What a client-side record store should treat this model's deletion as.
     *
     * A soft-deleted row is not always a "this record is gone" tombstone — a consuming app may
     * soft-delete for a reason the record store must not act on (e.g. "superseded, but still
     * meant to be shown", or "flagged wrong, but still meant to be shown and un-flaggable").
     * Deciding that per-resource, with a resource-level `typedData()` override, does not scale:
     * a tombstone applies per store key (one per model+id), so two resources serializing the
     * SAME model can disagree, and whichever response lands last silently wins.
     *
     * So the decision belongs on the MODEL, once — a model opts in by implementing
     * {@see HasRecordDeletedAt}, checked with `instanceof` (a declared contract, not a
     * `method_exists()` name-match) rather than falling back to the model's own `deleted_at`
     * when it declares none. Every resource serializing that model (today's callers and any
     * future one) then gets the same answer for free, and two resources for the same model can
     * no longer disagree. `private` (not `final` — PHP warns that `final` is meaningless on an
     * already-non-virtual private method) — this is the ONE call site the contract is read
     * from; a private method resolves non-virtually regardless of subclass, so this can never
     * be silently shadowed by a subclass override the way a `protected` one could.
     */
    private static function recordDeletedAtFor(Model $model): ?DateTimeInterface
    {
        return $model instanceof HasRecordDeletedAt ? $model->recordDeletedAt() : $model->deleted_at;
    }

    public static function make(?Model $model = null, array $includeFields = []): ?array
    {
        if (!method_exists(static::class, 'data')) {
            throw new Exception('Resource ' . static::class . ' must implement public static function data($model, $includeFields = []) { ... }');
        }

        if (!$model) {
            return null;
        }

        /** @noinspection PhpParamsInspection */
        $data = static::data($model, $includeFields);

        // Validate the includeFields (skip 'id' and '*' as they are handled automatically)
        foreach ($includeFields as $fieldName => $field) {
            if ($fieldName !== '*' && $fieldName !== 'id' && !array_key_exists($fieldName, $data)) {
                throw new Exception('Field "' . $fieldName . '" is not a valid field for ' . static::class);
            }
        }

        $responseData = [];

        foreach ($data as $fieldName => $datum) {
            $isCallable = !is_scalar($datum) && is_callable($datum);

            // If the * special field is set, the field is automatically included
            // If the field is explicitly set, either include or exclude based on the value
            $includedField = $includeFields[$fieldName] ?? $includeFields['*'] ?? !$isCallable;

            // If the field is not included, skip it
            if ($includedField === false) {
                continue;
            }

            // If the field is a callback, call it ONLY if it is explicitly included (do this recursively so child fields as well)
            if ($isCallable) {
                $responseData[$fieldName] = $datum(is_array($includedField) ? $includedField : []);
            } else {
                $responseData[$fieldName] = $datum;
            }
        }

        return static::typedData($model, $responseData);
    }

    public static function collection($collection, array $includeFields = [])
    {
        if (!$collection) {
            return [];
        }

        $items = [];

        foreach ($collection as $item) {
            $items[] = static::make($item, $includeFields);
        }

        return $items;
    }

    /**
     * Return the data for a model including all top-level fields
     *
     * NOTE: You should override this method if you need more deeply nested fields by default for the details view.
     *
     * Examples for deeply nesting:
     *  a) ['*' => ['*' => ["*" => true]]]
     *  b) ['*' => ['prop' => ['name' => true]]]
     */
    public static function details(Model $model, ?array $includeFields = null): array
    {
        return static::make($model, $includeFields ?? ['*' => true]);
    }
}
