<?php

namespace Newms87\DanxLaravel\Models;

use Exception;
use Newms87\DanxLaravel\Helpers\LockHelper;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class Ref extends Model
{
	const int CREATE_RETRIES = 20;

	protected $table    = 'refs';
	protected $fillable = ['prefix', 'ref'];

	/**
	 * Generates a unique Reference # with the given prefix and a minimum ref # length of minChars + prefix length
	 *
	 * @param string $prefix
	 * @param int    $minChars
	 * @return string
	 *
	 * @throws Throwable
	 */
	public static function generate(string $prefix = '', int $minChars = 5): string
	{
		LockHelper::acquire($prefix);
		$retries  = self::CREATE_RETRIES;
		$messages = '';

		// Keep trying to create a Ref until we make one successfully
		while($retries--) {
			$ref = $prefix . self::getNextRefNumber($prefix, $minChars);

			try {
				self::create([
					'prefix' => $prefix,
					'ref'    => $ref,
				]);

				LockHelper::release($prefix);

				return $ref;
			} catch(Exception $exception) {
				// Creation can fail if a Ref is duplicated by other parallel processes (ie: the lockWrite does not work)
				$messages .= $exception->getMessage() . "\n";
			}
		}

		LockHelper::release($prefix);
		throw new Exception("Failed to create a Ref Number for $prefix. The max number of retries limit has been reached: $messages");
	}

	/**
	 * @param $prefix
	 * @param $minChars
	 * @return string
	 */
	protected static function getNextRefNumber($prefix, $minChars): string
	{
		$maxRef = self::where('prefix', $prefix)->max('ref');
		$number = (int)str_replace($prefix, '', $maxRef) + 1;

		if (strlen((string)$number) < $minChars) {
			$number += (int)str_pad('1', $minChars, '0');
		}

		return $number;
	}
}
