<?php

namespace Newms87\Danx\Traits;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Newms87\Danx\Events\ModelSavedEvent;

trait BroadcastsWithSubscriptions
{
    use HasDebugLogging;

    /**
     * The subscription entries this model satisfied at the last {@see getSubscribedUsers()},
     * named by their cache keys, e.g. `subscribe:WorkflowInput:1:filter:<md5>`.
     *
     * ==== WHY AN EVENT HAS TO SAY THIS ====
     *
     * The channel is ONE per resource type per team ({@see getSubscribedChannels()}), so every
     * client on the team receives every event this gate lets through — and it lets one through
     * when ANY entry on the team matches. A client with a filtered list therefore cannot tell
     * "this matched my filter" from "this matched a teammate's wider subscription" unless the
     * event says which entries it satisfied. Observed 2026-09-11 in gpt-manager's Demand Desk:
     * a queue scoped to one schema counted a new demand of no schema at all, because another
     * member of the team held an unscoped WorkflowInput subscription.
     *
     * ==== WHY CACHE KEYS, NOT SOME OTHER NAME ====
     *
     * They are the strings the subscribe side wrote and this gate read, so they are the only
     * names both ends already hold: gpt-manager's subscribe route returns each entry's
     * `cache_key`, and a client compares that string against this list without parsing or
     * hashing anything. A second vocabulary would be a second thing to keep in step.
     *
     * Only entries that still have subscribers are named. Computing this costs nothing extra:
     * the gate already evaluates every entry to decide whether to broadcast at all.
     * {@see \Newms87\Danx\Events\ModelSavedEvent::broadcastWith()} sends it as `__subscriptions`.
     *
     * @var list<string>
     */
    protected array $satisfiedSubscriptions = [];

    /**
     * Get all user IDs subscribed to this resource, and record which subscription entries the
     * model satisfied ({@see $satisfiedSubscriptions}).
     *
     * @param  string  $resourceType  The resource type (e.g., "WorkflowRun")
     * @param  int|null  $teamId  The team ID (null for models without team association)
     * @param  Model  $model  The model instance
     * @param  string  $modelClass  The model class name for filtering
     * @return array Array of unique user IDs
     */
    protected function getSubscribedUsers(string $resourceType, ?int $teamId, Model $model, string $modelClass): array
    {
        $this->satisfiedSubscriptions = [];

        // No team = no subscriptions possible
        if ($teamId === null) {
            return [];
        }

        $prefix = "subscribe:{$resourceType}:{$teamId}";

        // Every entry this model satisfies, keyed by its cache key: channel-wide (ALL models of
        // this type), model-specific (this id), and every filter that matches it. An entry whose
        // subscriber list has emptied satisfies nobody, so it is dropped before it is named.
        $entries = array_filter([
            "{$prefix}:all"             => Cache::get("{$prefix}:all", []),
            "{$prefix}:id:{$model->id}" => Cache::get("{$prefix}:id:{$model->id}", []),
            ...$this->getMatchingFilterSubscriptions($resourceType, $teamId, $model, $modelClass),
        ]);

        $this->satisfiedSubscriptions = array_keys($entries);

        $uniqueUserIds = array_unique(array_merge([], ...array_values($entries)));

        // Single consolidated log entry
        if (!empty($uniqueUserIds)) {
            static::logDebug("Broadcasting {$resourceType}:{$model->id} to " . count($uniqueUserIds) . ' subscriber(s)');
        }

        return $uniqueUserIds;
    }

    /**
     * The filter-based subscription entries this model matches.
     *
     * @param  string  $resourceType  The resource type
     * @param  int|null  $teamId  The team ID
     * @param  Model  $model  The model instance
     * @param  string  $modelClass  The model class name for filtering
     * @return array<string, array> Subscriber user IDs, keyed by each matching filter's cache key
     */
    protected function getMatchingFilterSubscriptions(string $resourceType, ?int $teamId, Model $model, string $modelClass): array
    {
        $matching = [];

        // Get filter index for this resource/team
        $filterIndexKey = "subscribe:{$resourceType}:{$teamId}:filters";
        $filterHashes   = Cache::get($filterIndexKey, []);

        foreach ($filterHashes as $filterHash) {
            $filterKey = "subscribe:{$resourceType}:{$teamId}:filter:{$filterHash}";

            // Get filter definition
            $definitionKey = $filterKey . ':definition';
            $filter        = Cache::get($definitionKey);

            if ($filter === null) {
                continue;
            }

            // Apply filter to model and check if it matches
            try {
                // For deleted events, the model no longer exists in DB so we do in-memory attribute matching
                // Use property_exists to avoid undefined property access in non-event contexts
                $isDeletedEvent = property_exists($this, 'event') && $this->event === ModelSavedEvent::EVENT_DELETED;
                if ($isDeletedEvent) {
                    $matches = $this->matchesFilterInMemory($model, $filter);
                } else {
                    // whereKey() — never where('id', ...). The key must be QUALIFIED: a
                    // dot-notation filter (e.g. `teamObject.schema_definition_id`) JOINs the
                    // related table, which has its own `id`, and filter() only qualifies the
                    // wheres that exist when it builds that join. A bare `id` added after it is
                    // ambiguous, the query throws, the catch below swallows it, and every
                    // subscriber on a dot-notation filter silently stops hearing anything.
                    $matches = $modelClass::filter($filter)
                        ->whereKey($model->getKey())
                        ->exists();
                }

                if ($matches) {
                    $matching[$filterKey] = Cache::get($filterKey, []);
                }
            } catch (\Exception $e) {
                // Log error but continue - invalid filters shouldn't break broadcasting
                static::logDebug("Filter matching failed for key {$filterKey}: " . $e->getMessage());
            }
        }

        return $matching;
    }

    /**
     * Check if a model matches a filter using in-memory attribute comparison.
     * Used for deleted events where the model no longer exists in the database.
     *
     * @param  Model  $model  The model instance (with attributes still in memory)
     * @param  array  $filter  The filter definition
     * @return bool Whether the model matches the filter
     */
    protected function matchesFilterInMemory(Model $model, array $filter): bool
    {
        foreach ($filter as $key => $value) {
            // Skip special filter operators (and, or, etc.)
            if (in_array($key, ['and', 'or'])) {
                continue;
            }

            $modelValue = $model->getAttribute($key);

            // Handle array values (e.g., id: [1, 2, 3])
            if (is_array($value)) {
                if (!in_array($modelValue, $value)) {
                    return false;
                }
            } elseif ($modelValue != $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * Scan cache for keys matching pattern
     *
     * @param  string  $pattern  The pattern to match (e.g., "subscribe:WorkflowRun:5:filter:*")
     * @return array Array of matching cache keys
     */
    protected function scanCacheKeys(string $pattern): array
    {
        $keys  = [];
        $store = Cache::getStore();

        // Check if we're using Redis cache driver
        if (!method_exists($store, 'getRedis')) {
            // Fallback for non-Redis drivers (e.g., ArrayStore in tests)
            // Use an index-based approach for filter subscriptions
            return $this->scanCacheKeysWithIndex($pattern);
        }

        try {
            // Use Redis SCAN for efficient pattern matching
            $redis  = $store->getRedis();
            $cursor = '0';

            do {
                // SCAN returns [cursor, keys]
                $result = $redis->scan($cursor, ['MATCH' => $pattern, 'COUNT' => 100]);

                if ($result !== false) {
                    $cursor    = $result[0];
                    $foundKeys = $result[1] ?? [];

                    // Remove Laravel cache prefix if present
                    $cachePrefix = config('cache.prefix');
                    foreach ($foundKeys as $key) {
                        if ($cachePrefix && str_starts_with($key, $cachePrefix)) {
                            $key = substr($key, strlen($cachePrefix) + 1); // +1 for the colon separator
                        }
                        $keys[] = $key;
                    }
                }
            } while ($cursor !== '0');
        } catch (\RedisException $e) {
            static::logDebug("Redis error scanning cache keys for pattern {$pattern}: " . $e->getMessage());
        } catch (\Exception $e) {
            static::logDebug("Unexpected error scanning cache keys for pattern {$pattern}: " . $e->getMessage());
        }

        return $keys;
    }

    /**
     * Scan cache keys using an index (for non-Redis stores like ArrayStore)
     *
     * @param  string  $pattern  The pattern to match
     * @return array Array of matching cache keys
     */
    protected function scanCacheKeysWithIndex(string $pattern): array
    {
        // Convert wildcard pattern to regex pattern for matching
        // e.g., "subscribe:WorkflowRun:5:filter:*" becomes "subscribe:WorkflowRun:5:filter:.*"
        $regexPattern = '/^' . str_replace(['\\*', '\\?'], ['.*', '.'], preg_quote($pattern, '/')) . '$/';

        // Get index of all subscription keys (stored separately for non-Redis drivers)
        $indexKey = 'subscribe:_index';
        $allKeys  = Cache::get($indexKey, []);

        // Filter keys that match the pattern
        $matchingKeys = [];
        foreach ($allKeys as $key) {
            if (preg_match($regexPattern, $key)) {
                $matchingKeys[] = $key;
            }
        }

        return $matchingKeys;
    }

    /**
     * Convert user IDs to team-based PrivateChannel instances
     *
     * @param  string  $resourceType  The resource type
     * @param  int|null  $teamId  The team ID (null for models without team association)
     * @param  array  $userIds  Array of user IDs
     * @return array Array of PrivateChannel instances
     */
    protected function getSubscribedChannels(string $resourceType, ?int $teamId, array $userIds): array
    {
        // If no team or no users are subscribed, return empty array (no broadcast)
        if ($teamId === null || empty($userIds)) {
            return [];
        }

        // Return team channel (not user-specific channels)
        // All subscribed users will receive the event on the team channel
        return [new PrivateChannel("{$resourceType}.{$teamId}")];
    }
}
