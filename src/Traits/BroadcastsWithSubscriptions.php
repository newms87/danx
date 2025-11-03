<?php

namespace Newms87\Danx\Traits;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

trait BroadcastsWithSubscriptions
{
    use HasDebugLogging;

    /**
     * Get all user IDs subscribed to this resource
     *
     * @param  string  $resourceType  The resource type (e.g., "WorkflowRun")
     * @param  int|null  $teamId  The team ID (null for models without team association)
     * @param  Model  $model  The model instance
     * @param  string  $modelClass  The model class name for filtering
     * @return array Array of unique user IDs
     */
    protected function getSubscribedUsers(string $resourceType, ?int $teamId, Model $model, string $modelClass): array
    {
        // No team = no subscriptions possible
        if ($teamId === null) {
            return [];
        }

        $userIds = [];

        // 1. Check channel-wide subscriptions (subscribe to ALL models of this type)
        $channelWideKey   = "subscribe:{$resourceType}:{$teamId}:all";
        $channelWideUsers = Cache::get($channelWideKey, []);
        $userIds          = array_merge($userIds, $channelWideUsers);

        // 2. Check model-specific subscriptions (subscribe to this specific model ID)
        $modelSpecificKey   = "subscribe:{$resourceType}:{$teamId}:id:{$model->id}";
        $modelSpecificUsers = Cache::get($modelSpecificKey, []);
        $userIds            = array_merge($userIds, $modelSpecificUsers);

        // 3. Check filter-based subscriptions
        $filterUsers = $this->getFilterBasedSubscribers($resourceType, $teamId, $model, $modelClass);
        $userIds     = array_merge($userIds, $filterUsers);

        // Deduplicate and return
        return array_unique($userIds);
    }

    /**
     * Get users subscribed via filter-based subscriptions
     *
     * @param  string  $resourceType  The resource type
     * @param  int|null  $teamId  The team ID
     * @param  Model  $model  The model instance
     * @param  string  $modelClass  The model class name for filtering
     * @return array Array of user IDs
     */
    protected function getFilterBasedSubscribers(string $resourceType, ?int $teamId, Model $model, string $modelClass): array
    {
        $userIds = [];

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
                $matches = $modelClass::filter($filter)
                    ->where('id', $model->id)
                    ->exists();

                if ($matches) {
                    $subscribers = Cache::get($filterKey, []);
                    $userIds     = array_merge($userIds, $subscribers);
                }
            } catch (\Exception $e) {
                // Log error but continue - invalid filters shouldn't break broadcasting
                static::logDebug("Filter matching failed for key {$filterKey}: " . $e->getMessage());
            }
        }

        return $userIds;
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
