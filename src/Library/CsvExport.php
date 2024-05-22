<?php

namespace Newms87\Danx\Library;

class CsvExport
{
	protected array $records;

	public function __construct(array $records = [])
	{
		$this->records = $records;
	}

	public function headings(): array
	{
		return $this->records ? array_keys((array)$this->records[0]) : [];
	}

	public function array(): array
	{
		return $this->records;
	}

	public function getCsvContent(): string
	{
		$handle = fopen('php://temp', 'r+');
		fputcsv($handle, $this->headings());
		foreach($this->array() as $row) {
			fputcsv($handle, (array)$row);
		}
		rewind($handle);
		$csv = stream_get_contents($handle);
		fclose($handle);

		return $csv ?: '';
	}
}
