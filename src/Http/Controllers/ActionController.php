<?php

namespace Newms87\Danx\Http\Controllers;

use Exception;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Newms87\Danx\Exceptions\ValidationError;
use Newms87\Danx\Helpers\FileHelper;
use Newms87\Danx\Models\Audit\ErrorLog;
use Newms87\Danx\Repositories\ActionRepository;
use Newms87\Danx\Requests\PagerRequest;
use Newms87\Danx\Resources\ActionResource;
use Throwable;

abstract class ActionController extends Controller
{
	/** @var string|ActionRepository Set to the model's repository */
	public static string $repo;

	/** @var string|ActionResource Set to the resource class for the model */
	public static ?string $resource;

	public function __construct()
	{
		if (!static::$repo) {
			throw new Exception('Please set the static $repo property in the ' . static::class . ' class');
		}

		if (!static::$resource) {
			throw new Exception('Please set the static $resource property in the ' . static::class . ' class');
		}
		// TODO: Remove Controller and replace routes with the repo directly
	}

	public function repo(): ActionRepository
	{
		return app(static::$repo);
	}

	/**
	 * A single formatted model
	 */
	protected function item(Model|Collection|array|null $instance): array
	{
		return static::$resource::make($instance);
	}

	/**
	 * A list of formatted models
	 */
	protected function collection(array|Collection|null $instances): AnonymousResourceCollection|array|Collection
	{
		return static::$resource::collection($instances);
	}

	/**
	 * @param PagerRequest $request
	 * @return array
	 * @throws Exception
	 */
	public function list(PagerRequest $request)
	{
		$results = $this->repo()->listQuery()
			->filter($request->filter())
			->sort($request->sort())
			->paginate($request->perPage(50));

		return [
			'data' => $this->collection($results->items()),
			'meta' => [
				'total'        => $results->total(),
				'current_page' => $results->currentPage(),
				'per_page'     => $results->perPage(),
			],
		];
	}

	/**
	 * Retrieve a summary of the filtered list of items. Totals, counts, etc.
	 */
	public function summary(PagerRequest $request): array|object
	{
		return $this->repo()->summary($request->filter());
	}

	/**
	 * Return the item details for a detail view / filling out defaults in an editable form / etc.
	 */
	public function details($model): mixed
	{
		if (!$model) {
			return response('Item not found', 404);
		}

		return static::$resource::details($model);
	}

	/**
	 * Retrieve a related resource for the given model
	 */
	public function relation($model, $relation): mixed
	{
		return static::$resource::relation($model, $relation);
	}

	/**
	 * Retrieve the data to populate the list of filters on the collection. Used for dropdowns, etc.
	 */
	public function fieldOptions(PagerRequest $request): array
	{
		return $this->repo()->fieldOptions($request->filter());
	}

	/**
	 * @param Model|null   $model
	 * @param PagerRequest $request
	 * @return Response
	 */
	public function applyAction(?Model $model, PagerRequest $request)
	{
		$input  = $request->input();
		$action = $input['action'] ?? $request->get('action');
		$data   = $input['data'] ?? $request->get('data', []);

		try {
			$result = $this->repo()->applyAction($action, $model, $data);

			if (!$model && ($result instanceof Model)) {
				$model  = $result;
				$result = true;
			}

			return response([
				'success' => true,
				'result'  => $result,
				'item'    => $model ? $this->details($model->refresh()) : null,
			]);
		} catch(Throwable $throwable) {
			$response = [
				'error'   => true,
				'message' => $throwable->getMessage(),
			];

			if (config('app.debug') && !($throwable instanceof ValidationError)) {
				$response += [
					'class' => get_class($throwable),
					'file'  => $throwable->getFile(),
					'line'  => $throwable->getLine(),
					'trace' => $throwable->getTrace(),
				];
			}
			ErrorLog::logException(ErrorLog::ERROR, $throwable);

			return response($response, 400);
		}
	}

	/**
	 * @param PagerRequest $request
	 * @return Response
	 * @throws Throwable
	 */
	public function batchAction(PagerRequest $request)
	{
		$filter = $request->filter();
		$input  = $request->input();
		$action = $input['action'] ?? $request->get('action');
		$data   = $input['data'] ?? $request->get('data', []);

		if (!$filter || empty($filter['id'])) {
			return response("Selection is required for batch updates", 400);
		}

		if (!$action) {
			return response("No action provided", 400);
		}

		$errors = $this->repo()->batchAction($filter, $action, $data);

		return response([
			'success' => !$errors,
			'errors'  => $errors,
		]);
	}

	/**
	 * Generate a CSV export of all the fields defined in the repository export filtering records by the given filter
	 */
	public function export(PagerRequest $request): string
	{
		$export = $this->repo()->export($request->filter() ?? []);

		return FileHelper::exportCsv($export ?? []);
	}
}
