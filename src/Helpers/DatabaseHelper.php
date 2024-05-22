<?php

namespace Newms87\Danx\Helpers;

use Illuminate\Support\Facades\DB;

class DatabaseHelper
{
	/**
	 * Insert a large # of records into the DB efficiently
	 *
	 * @param     $table
	 * @param     $records
	 * @param int $flushCount
	 * @return bool
	 */
	public static function bulkInsert($table, $records, $flushCount = 1000)
	{
		if (empty($records)) {
			return true;
		}

		$keys = implode(',', array_keys($records[0]));

		$sql = "INSERT INTO `$table` ($keys) VALUES ";

		$count  = 0;
		$values = '';

		foreach($records as $index => $record) {
			//Escape the data being inserted
			foreach($record as &$value) {
				if ($value === null) {
					$value = 'null';
				} elseif ($value !== 'NULL') {
					$value = DB::connection()->getPdo()->quote($value);
				}
			}
			unset($value);

			//Concatenate the values to insert
			$values .= ($values ? ',' : '') . '(' . implode(',', $record) . ')';

			//Flush the values buffer to the DB
			if ($count++ >= $flushCount) {
				DB::insert($sql . $values);

				//Reset the count and values
				$count  = 0;
				$values = '';
			}
		}

		//Flush any remaining values
		if ($count > 0) {
			return DB::insert($sql . $values);
		} else {
			return true;
		}
	}

	/**
	 * Efficiently update a large # of records in the database
	 *
	 * @param $table
	 * @param $records
	 */
	public static function massUpdate($table, $records)
	{
		$pdo = DB::getPdo();

		$keysStr = '';

		foreach($records[0] as $key => $value) {
			if ($key !== 'id') {
				$keysStr .= ($keysStr ? ',' : '') . "`$key` = :$key";
			}
		}

		$sth = $pdo->prepare("UPDATE `$table` SET $keysStr WHERE `id` = :id");

		foreach($records as $record) {
			$sth->execute($record);
		}
	}
}
