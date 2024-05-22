<?php

namespace Newms87\DanxLaravel\Helpers;

use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SafeMigrationHelper
{
	protected $table;

	protected $tempTable;

	protected $backupTable;

	protected $primaryKey;

	// An array of callbacks that will modify the temporary table
	protected $alterations = [];

	// Specify the Record range to sync from 1 to $endingRecordId
	protected $endingRecordId;

	// The columns to sync between the old and the new table
	protected $columns;

	// If this table uses an Auto Increment field, this should be enabled
	protected $usesAutoIncrement;

	// The numbers of insertions made for this connection
	protected $connectionInsertionCount = 0;

	// The list of timings for each insertion
	protected $insertTimes = [];

	// The # of records to insert in each transaction
	protected $increment = 1000;

	// The minimum allowed increment (in case the adjusted increment falls too low)
	protected $minIncrement = 100;

	// Time to complete insert transaction (in ms)
	protected $insertTimeThreshold = 1000;

	// Do not adjust increment until the threshold has been breached this many times
	protected $incrementAdjustmentBreachCount = 3;

	// The current # of consecutive threshold breaches
	protected $breachCount = 0;

	// The % amount to adjust the increment by, when a insert threshold breach has occurred
	protected $incrementAdjustmentFactor = .75;

	// The time in seconds to delay after disconnecting from multiple threshold breaches the DB (to make sure DB catches up)
	protected $delayAfterDisconnect = 120;

	// The # of insertions to make before automatically disconnecting
	protected $autoDisconnectLimit = 30;

	// The time in seconds to delay after disconnecting from auto disconnect
	protected $delayAfterAutoDisconnect = 1;

	// If this migration has already been started, we want to continue where we left off
	protected $isIncompleteMigration = false;

	// Echo the current status of the procedure as it progresses
	protected $liveReporting = true;

	/**
	 * SafeMigrationHelper constructor.
	 *
	 * @param $table - the table to modify safely (without locking / losing data)
	 */
	public function __construct($table, $primaryKey = 'id')
	{
		$this->table       = $table;
		$this->tempTable   = $table . '_tmp';
		$this->backupTable = $table . '_bkp';

		$this->primaryKey = $primaryKey;

		// If this schema has the backup table already, we assume the migration has already started and we are continuing an
		// incomplete migration
		if (Schema::hasTable($this->backupTable)) {
			$this->isIncompleteMigration = true;

			$this->report('Backup table already existed. Continuing from an incomplete migration and skipping alteration.');
		}
	}

	/**
	 * Log the transaction to the console (or wherever makes sense)
	 *
	 * @param $msg
	 */
	public function report($msg, $overwrite = false)
	{
		static $overwriteLastLine, $lastLineLength;

		if ($this->liveReporting) {
			if ($overwriteLastLine) {
				echo $overwrite ? "\r" : "\n";
			}

			if ($overwrite) {
				echo str_pad($msg, $lastLineLength, ' ', STR_PAD_RIGHT);
			} else {
				echo "$msg\n";
			}

			flush();
		}

		$lastLineLength    = strlen($msg);
		$overwriteLastLine = $overwrite;
	}

	/**
	 * Enable / Disable live reporting
	 *
	 * @param $enabled
	 * @return SafeMigrationHelper
	 */
	public function setLiveReporting($enabled)
	{
		$this->liveReporting = $enabled;

		return $this;
	}

	/**
	 * Set the # of records to insert in each transaction
	 *
	 * @param $increment
	 * @return SafeMigrationHelper
	 */
	public function setIncrement($increment)
	{
		$this->increment = $increment;

		return $this;
	}

	/**
	 * Columns to exclude from the sync. Typically used for generated columns
	 *
	 * @param $excludedColumns
	 * @return $this
	 */
	public function excludeColumns($excludedColumns)
	{
		if (!$this->columns) {
			$this->syncColumns('*');
		}

		$this->columns = array_diff($this->columns, $excludedColumns);

		return $this;
	}

	/**
	 * Define the columns to sync from the old table to the new table
	 *
	 * @param $columns
	 * @return $this
	 */
	public function syncColumns($columns = '*')
	{
		if ($columns === '*') {
			// If this is an incomplete migration, the column list will exist in the backup table
			$this->columns = Schema::getColumnListing($this->isIncompleteMigration ? $this->backupTable : $this->table);
		} else {
			$this->columns = $columns;
		}

		return $this;
	}

	/**
	 * Enable syncing auto increment
	 *
	 * @param bool $autoIncrement
	 * @return SafeMigrationHelper
	 */
	public function syncAutoIncrement($autoIncrement = true)
	{
		$this->usesAutoIncrement = $autoIncrement;

		return $this;
	}

	/**
	 * Queue changes to the table (add indexes, fields, etc.)
	 *
	 * @param callable $callback
	 * @return SafeMigrationHelper
	 */
	public function alter(callable $callback)
	{
		$this->alterations[] = $callback;

		return $this;
	}

	/**
	 * Perform the request migration safely without locking tables by back-filling
	 * the records into a newly created table
	 *
	 * @return self
	 *
	 * @throws Exception
	 */
	public function migrate()
	{
		// If columns have not been set, sync all columns
		if (!$this->columns) {
			$this->syncColumns('*');
		}

		$columns = $this->getEscapedColumns();

		$this->report("Safely migrating $this->table ($columns)");

		// We need to create a temporary table, make all the changes requested, then hot swap this temporary table
		// to be used as the new active table. If this is an incomplete migration, this process will have already
		// been done, so we should not do it again
		if (!$this->isIncompleteMigration) {
			// Setup a temp table to make changes to
			$this->setupTemporaryTable();

			// Make changes on the temp table
			$this->makeAlterations();

			// Swap the temporary table as the new active table (will be used immediately in this environment)
			// and set the original table as the backup table (the data will be sync'd to the new table according to the appropriate algorithm)
			$this->swapInNewTable();
		}

		if ($this->usesAutoIncrement) {
			$this->migrateAutoIncrement($columns);
		} else {
			throw new Exception('Unhandled algorithm. This needs to be implemented first!');
		}

		return $this;
	}

	/**
	 * Return the safely formatted columns to sync
	 *
	 * @return string
	 */
	public function getEscapedColumns()
	{
		return '`' . implode('`,`', $this->columns) . '`';
	}

	/**
	 * Create a temporary table that looks exactly like the original table (without the data)
	 */
	protected function setupTemporaryTable()
	{
		if (Schema::hasTable($this->tempTable)) {
			$this->report('The temporary table had already been created. Trashing temporary table to start over.');
			Schema::drop($this->tempTable);
		}

		// Create the temporary table to replace the table after making alterations.
		DB::statement("CREATE TABLE `$this->tempTable` LIKE `$this->table`");
	}

	/**
	 * Execute the alterations / changes to be made to the temp table
	 */
	protected function makeAlterations()
	{
		foreach($this->alterations as $alteration) {
			Schema::table($this->tempTable, $alteration);
		}
	}

	/**
	 * Swap out the old table for the new table so records start populating in the new table, freezing
	 * the old table in order to sync all the records into the new table
	 *
	 * @return $this
	 */
	protected function swapInNewTable()
	{
		$this->report('Swapping old table for new table (the new table will immediately be in use in this environment)');

		DB::transaction(function () {
			DB::statement("RENAME TABLE `$this->table` TO `$this->backupTable`, `$this->tempTable` TO `$this->table`");
		});

		return $this;
	}

	/**
	 * Migrate the new table
	 *
	 * @param $columns
	 */
	protected function migrateAutoIncrement($columns)
	{
		// Black any new insertions so we do not miss any new data in this transaction
		$this->lockTables([$this->table => 'WRITE', $this->backupTable => 'WRITE']);

		$this->report('Synchronizing using the Auto Increment Algorithm');
		$endingRecordId = $this->getEndingRecordId();

		// We only want to sync tables if there is at least 1 record to sync
		if ($endingRecordId > 0) {
			// Set the auto increment to accommodate the previous digital previews data (but not if this is an incomplete migration, as this will have already been done)
			$this->setAutoIncrement($this->table, $endingRecordId);

			// Allow writes / insertions to be committed to the this table again
			$this->unlockTables();

			$end = $endingRecordId;

			// Reset the insert time threshold breach count
			$this->breachCount = 0;

			for(; $end > 0; $end -= $this->increment) {
				$start = max(1, $end - $this->increment + 1);

				// Track timing for insert statement
				$timeStart = microtime(true);

				DB::statement("INSERT INTO `$this->table` ($columns) SELECT $columns FROM `$this->backupTable` WHERE `$this->primaryKey` BETWEEN $start AND $end");

				$insertTime      = 1000 * (microtime(true) - $timeStart);
				$roundedTime     = round($insertTime, 2);
				$percentComplete = round(100 * ($endingRecordId - $end) / $endingRecordId, 2);

				$this->insertTimes[] = $insertTime;

				$timeRemaining = $this->estimateTimeRemaining($end);

				$this->report("Inserted $this->increment records ($start to $end) in {$roundedTime}ms --- $percentComplete% ($timeRemaining remaining)",
					true);

				$end = $this->handleInsertThresholdBreach($insertTime, $end);
			}
		} else {
			// Need to unlock the tables
			$this->unlockTables();
		}
	}

	/**
	 * Lock a given table for a given access type (eg: READ or WRITE)
	 *
	 * @param $tables - an array of tables to lock in format ['table_name' => '(WRITE|READ)']
	 * @return SafeMigrationHelper
	 */
	public function lockTables($tables)
	{
		foreach($tables as $table => $lockType) {
			$locks[] = "`$table` $lockType";
		}

		DB::unprepared('LOCK TABLES ' . implode(', ', $locks));

		return $this;
	}

	/**
	 * Determines the last record in an auto-incrementing table to sync from the temp table to the new table
	 *
	 * @return int|mixed
	 */
	public function getEndingRecordId()
	{
		if (!$this->endingRecordId) {
			$tempTableEnd  = DB::table($this->backupTable)->max($this->primaryKey);
			$newTableStart = DB::table($this->table)->min($this->primaryKey);

			// If the new table already has records from the temp table, then we want to use the new table's oldest record to pick up from
			// an incomplete migration
			if ($newTableStart && $newTableStart < $tempTableEnd) {
				$this->endingRecordId = (int)$newTableStart - 1;

				$this->report("Continuing from an incomplete migration at record ID $this->endingRecordId");
			} else {
				$this->endingRecordId = (int)$tempTableEnd;

				$this->report("Migrating all records from 1 to $this->endingRecordId");
			}
		}

		return $this->endingRecordId;
	}

	/**
	 * Set the endingRecordId to tell the auto-increment algorithm to sync a range from 1 to $id
	 *
	 * @param $id
	 * @return $this
	 */
	public function setEndingRecordId($id)
	{
		$this->endingRecordId = $id;

		return $this;
	}

	/**
	 * @param $id
	 * @return SafeMigrationHelper
	 */
	protected function setAutoIncrement($table, $id)
	{
		DB::statement("ALTER TABLE `$table` AUTO_INCREMENT = $id");

		return $this;
	}

	/**
	 * Unlock all tables
	 *
	 * @return $this
	 */
	public function unlockTables()
	{
		DB::unprepared('UNLOCK TABLES');

		return $this;
	}

	/**
	 * Returns a human readable formatted time remaining estimate
	 *
	 * @param $remainingRecordCount
	 * @return string
	 */
	protected function estimateTimeRemaining($remainingRecordCount)
	{
		$lastTimeIndex = count($this->insertTimes);

		// use the last 1000 insertions to estimate the time remaining
		$estimateStartIndex = max($lastTimeIndex - 1000, 0);

		$total = 0;
		for($i = $estimateStartIndex; $i < $lastTimeIndex; $i++) {
			$total += $this->insertTimes[$i];
		}

		$avgTime = $total / ($lastTimeIndex - $estimateStartIndex);

		$transactionsRemaining = $remainingRecordCount / $this->increment;

		// The estimated remaining time in seconds
		$seconds = round($transactionsRemaining * $avgTime / 1000);

		return DateHelper::timeToString($seconds);
	}

	/**
	 * Checks if the insert time threshold has been breached. If it has been breached more consecutive times than
	 * the breachCount allows, then we need to adjust the increment.
	 *
	 * Returns the next last record ID to account for the adjusted increment assuming the next iteration will decrement
	 * by the new increment
	 *
	 * @param $insertTime
	 * @param $end
	 * @return int|mixed
	 */
	protected function handleInsertThresholdBreach($insertTime, $end)
	{
		// If the insert was over the insert time threshold, we need to adjust the increment to avoid overloading the DB
		if ($insertTime > $this->insertTimeThreshold) {
			$this->breachCount++;

			// If the # of consecutive breaches is greater than the allowed breach count and the increment is still above the minimum,
			// we want to adjust the increment to optimize the performance of this migration
			if ($this->breachCount >= $this->incrementAdjustmentBreachCount && $this->increment > $this->minIncrement) {
				// Halve the increment and adjust the next ID range so we do not overlap with the previous range
				$end             -= $this->increment;
				$this->increment = max($this->minIncrement,
					floor($this->increment * $this->incrementAdjustmentFactor));
				// The loop will decrement by this much, but we're already at the correct ID
				$end += $this->increment;

				// Reset the breach count
				$this->breachCount = 0;

				$this->report("Insertion time more than {$this->insertTimeThreshold}ms for $this->incrementAdjustmentBreachCount insertions. Adjusting increment to $this->increment and disconnecting from database for $this->delayAfterDisconnect seconds.");

				$this->resetConnection($this->delayAfterDisconnect);

				$this->report('Reconnected to database. Resuming migration...');
			}
		} else {
			$this->breachCount = 0;

			if ($this->connectionInsertionCount++ > $this->autoDisconnectLimit) {
				$this->resetConnection($this->delayAfterAutoDisconnect);
				$this->report("Delaying for $this->delayAfterAutoDisconnect", true);
			}
		}

		return $end;
	}

	/**
	 * Reset the DB connection with a delay
	 *
	 * @param $delay - the delay in seconds before reconnecting
	 */
	public function resetConnection($delay)
	{
		// Try resetting the database connection to hopefully speed up the process
		DB::disconnect();

		$this->pause($delay);

		DB::reconnect();

		// reset the insertions made for this connection
		$this->connectionInsertionCount = 0;
	}

	/**
	 * Pause the execution of the script for $duration seconds
	 *
	 * @param $duration
	 */
	public function pause($duration)
	{
		// Give it some time for the database to catch up with all transactions
		for($i = $duration; $i > 0; $i--) {
			$this->report("Resuming in $i seconds...", true);
			sleep(1);
		}
	}

	/**
	 * @return $this
	 */
	public function optimize()
	{
		$this->report("Optimizing table $this->table (this may take a while)...");
		DB::statement("OPTIMIZE TABLE `$this->table`");

		return $this;
	}

	/**
	 * Drops the old tables as they are no longer needed.
	 *
	 * @throws Exception
	 */
	public function clean()
	{
		Schema::dropIfExists($this->tempTable);

		$backupCount = (int)DB::table($this->backupTable)->count();

		if ($backupCount > 0) {
			$lastRecordId = (int)DB::table($this->backupTable)->max($this->primaryKey);

			$newCount = (int)DB::table($this->table)
				->where($this->primaryKey, '<=', $lastRecordId)
				->count();
		}

		// Only drop the new table if all the data made it into the new table
		if ($backupCount === 0 || $backupCount === $newCount) {
			$this->report('All records were synchronized successfully!Removing backup table');
			Schema::dropIfExists($this->backupTable);
		} else {
			$this->report("There were missing records in the new table!Please try re - running the migration, or manually fix this problem . Leaving the backup table $this->backupTable which contains the missing records . ");
			throw new Exception('Failed to complete the migration!There were records missing');
		}
	}
}
