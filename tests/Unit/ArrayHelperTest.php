<?php

namespace Tests\Unit;

use Illuminate\Foundation\Testing\TestCase;
use Newms87\Danx\Helpers\ArrayHelper;

class ArrayHelperTest extends TestCase
{
	public function test_extractNestedData_producesEmptyArrayFromStringArtifact(): void
	{
		// Given
		$artifact       = 'string artifact';
		$includedFields = ['name'];

		// When
		$includedData = ArrayHelper::extractNestedData($artifact, $includedFields);

		// Then
		$this->assertEquals([], $includedData);
	}

	public function test_extractNestedData_producesEmptyArrayWhenIndexNotSet(): void
	{
		// Given
		$artifact       = ['dob' => '1987-11-18'];
		$includedFields = ['name'];

		// When
		$includedData = ArrayHelper::extractNestedData($artifact, $includedFields);

		// Then
		$this->assertEquals([], $includedData);
	}

	public function test_extractNestedData_producesIdenticalArtifactWhenIncludedFieldsEmpty(): void
	{
		// Given
		$artifact       = [
			'name' => 'Dan Newman',
			'dob'  => '1987-11-18',
		];
		$includedFields = [];

		// When
		$includedData = ArrayHelper::extractNestedData($artifact, $includedFields);

		// Then
		$this->assertEquals($artifact, $includedData);
	}

	public function test_extractNestedData_producesSubsetOfArtifactWhenIncludedFieldsSet(): void
	{
		// Given
		$artifact       = [
			'name' => 'Dan Newman',
			'dob'  => '1987-11-18',
		];
		$includedFields = ['name'];

		// When
		$includedData = ArrayHelper::extractNestedData($artifact, $includedFields);

		// Then
		$this->assertEquals(['name' => $artifact['name']], $includedData);
	}

	public function test_extractNestedData_producesChildObjectWhenIndexReferencesObject(): void
	{
		// Given
		$artifact       = [
			'name'    => 'Dan Newman',
			'dob'     => '1987-11-18',
			'address' => [
				'street' => '123 Main St',
				'city'   => 'Anytown',
				'state'  => 'NY',
				'zip'    => '12345',
			],
		];
		$includedFields = ['address'];

		// When
		$includedData = ArrayHelper::extractNestedData($artifact, $includedFields);

		// Then
		$this->assertEquals(['address' => $artifact['address']], $includedData);
	}

	public function test_extractNestedData_producesArrayWithAllElementsWhenIndexReferencesArray(): void
	{
		// Given
		$artifact       = [
			'name'    => 'Dan Newman',
			'dob'     => '1987-11-18',
			'aliases' => ['Hammer', 'Tater Salad', 'Daniel'],
		];
		$includedFields = ['aliases'];

		// When
		$includedData = ArrayHelper::extractNestedData($artifact, $includedFields);

		// Then
		$this->assertEquals(['aliases' => $artifact['aliases']], $includedData);
	}

	public function test_extractNestedData_producesArrayWithSpecifiedIndexKeysForEachElementWhenIndexUsesAsteriskSyntax(): void
	{
		// Given
		$artifact       = [
			'name'      => 'Dan Newman',
			'dob'       => '1987-11-18',
			'addresses' => [
				[
					'street' => '123 Main St',
					'city'   => 'Anytown',
					'state'  => 'NY',
					'zip'    => '12345',
					'type'   => 'primary',
				],
				[
					'street' => '444 2nd St',
					'city'   => 'Evergreen',
					'state'  => 'NY',
					'zip'    => '80033',
					'type'   => 'shipping',
				],
				[
					'street' => '555 Not Here St',
					'city'   => 'Springfield',
					'state'  => 'CO',
					'zip'    => '800349',
					'type'   => 'billing',
				],
			],
		];
		$includedFields = ['addresses.*.zip', 'addresses.*.type'];

		// When
		$includedData = ArrayHelper::extractNestedData($artifact, $includedFields);

		// Then
		$this->assertEquals([
			'addresses' => [
				['zip' => '12345', 'type' => 'primary'],
				['zip' => '80033', 'type' => 'shipping'],
				['zip' => '800349', 'type' => 'billing'],
			],
		], $includedData);
	}
	
	public function test_groupByDot()
	{
		// Given
		$array = [
			'service_dates' => [
				[
					'date' => '2021-01-01',
					'data' => 1,
				],
				[
					'date' => '2021-01-02',
					'data' => 2,
				],
			],
			'other'         => 'stuff',
		];

		// When
		$result = ArrayHelper::groupByDot($array, 'service_dates.date');

		// Then
		$this->assertEquals([
			'2021-01-01' => [
				'date' => '2021-01-01',
				'data' => 1,
			],
			'2021-01-02' => [
				'date' => '2021-01-02',
				'data' => 2,
			],
		], $result);
	}

	public function test_groupByDot_handlesSingleLevelArray()
	{
		// Given
		$array = [
			'questions' => [
				"Question 1",
				"Question 2",
				"Question 3",
			],
		];

		// When
		$result = ArrayHelper::groupByDot($array, 'questions');

		// Then
		$this->assertEquals([
			"Question 1",
			"Question 2",
			"Question 3",
		], $result);
	}
}
