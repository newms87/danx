<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property $id
 *
 * @mixin Model
 */
interface User extends Authenticatable
{
	public function can($abilities, $arguments = []);
}
