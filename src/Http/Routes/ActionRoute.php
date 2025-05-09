<?php

namespace Newms87\Danx\Http\Routes;


use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\RouteRegistrar;
use Illuminate\Support\Facades\Route;
use Newms87\Danx\Http\Controllers\ActionController;
use Newms87\Danx\Requests\PagerRequest;

class ActionRoute extends Route
{
	/**
	 * @param string           $name
	 * @param ActionController $controller The class name of a controller extending ActionController
	 * @param null             $extend     A closure to add custom routes
	 * @return RouteRegistrar
	 */
	public static function routes(string $name, ActionController $controller, $extend = null): RouteRegistrar
	{
		// Strict naming / prefixing rules to ensure consistency
		$prefix = str_replace('.', '/', $name);

		return static::prefix($prefix)->withoutMiddleware([VerifyCsrfToken::class])->group(function () use ($name, $controller, $extend) {
			$getPost = ['GET', 'HEAD', 'POST'];
			self::get('{id}/edit', fn($model) => redirect(app_url("$name/$model->id/edit"), ''))->name($name . '.edit');

			// GET Data - NOTE: POST is included since filters can be too long for URLs in some browsers
			self::addRoute($getPost, 'list', [$controller::class, 'list'])->name($name . '.list');
			self::addRoute($getPost, 'summary', [$controller::class, 'summary'])->name($name . '.summary');
			self::addRoute($getPost, 'field-options', [$controller::class, 'fieldOptions'])->name($name . '.field-options');
			self::get('{id}/details', fn($model) => $controller->details($controller->repo()->instance($model)))->name($name . '.details');
			self::get('{id}/relation/{relation}', fn($model, $relation) => $controller->relation($controller->repo()->instance($model), $relation))->name($name . '.relation');
			self::addRoute($getPost, 'export', [$controller::class, 'export'])->name($name . '.export');

			// Actions
			self::post('{id}/apply-action', fn($model, PagerRequest $request) => $controller->applyAction($controller->repo()->instance($model), $request))->name($name . '.apply-action');
			self::post('apply-action', fn(PagerRequest $request) => $controller->applyAction(null, $request))->name($name . '.apply-action.create');
			self::post('batch-action', [$controller::class, 'batchAction'])->name($name . '.batch-action');

			// Custom routes
			if ($extend) {
				$extend();
			}
		});
	}
}
