<?php

namespace Newms87\Danx\Traits;

/**
 * Provides a standardized resource ID format (#XX-{id}) for models displayed in the UI.
 *
 * Each model using this trait must declare a RESOURCE_ID_PREFIX constant (e.g., 'TW' for TaskWorker).
 * The resourceId() method returns the formatted string (e.g., '#TW-57').
 *
 * Prefix map is declared in .claude/rules/resource-id-formats.md.
 * This does NOT replace __toString() — debug logging continues to use the <Model id='X' name='Y'> format.
 */
trait HasResourceId
{
    public function resourceId(): string
    {
        return '#' . static::RESOURCE_ID_PREFIX . '-' . $this->id;
    }
}
