<?php

namespace Newms87\DanxLaravel\Http\Controllers;

use Exception;
use Newms87\DanxLaravel\Exceptions\ValidationError;
use Newms87\DanxLaravel\Helpers\FileHelper;
use Newms87\DanxLaravel\Models\Audit\ErrorLog;
use Newms87\DanxLaravel\Repositories\ActionRepository;
use Newms87\DanxLaravel\Requests\PagerRequest;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Throwable;

abstract class ActionController extends Controller
{
	/** @var string|ActionRepository Set to the model's repository */
	public static string $repo;

	/** @var string|JsonResponse Set to the resource class for the model */
	public static ?string $resource;

	/** @var string|JsonResponse|null Set to the details resource class for the model */
	public static ?string $detailsResource = null;

	public function __construct()
	{
		if (!static::$repo) {
			throw new Exception('Please set the static $repo property in the ' . static::class . ' class');
		}
	}

	public function repo(): ActionRepository
	{
		return app(static::$repo);
	}

	/**
	 * @param Model $instance
	 * @return JsonResponse|mixed
	 */
	protected function item($instance)
	{
		if (static::$resource) {
			return static::$resource::make($instance);
		}

		return $instance;
	}

	/**
	 * @param Model $instance
	 * @return JsonResponse|mixed
	 */
	protected function itemDetails($instance)
	{
		if (static::$detailsResource) {
			return static::$detailsResource::make($instance)->toArray(request());
		}

		return $instance;
	}

	/**
	 * @param Model[]|Collection $instances
	 * @return AnonymousResourceCollection|array|Collection
	 */
	protected function collection($instances)
	{
		if (static::$resource) {
			return static::$resource::collection($instances);
		}

		return $instances;
	}

	/**
	 * @param PagerRequest $request
	 * @return AnonymousResourceCollection
	 * @throws Exception
	 */
	public function list(PagerRequest $request)
	{
		$results = $this->repo()->listQuery()
			->filter($request->filter())
			->sort($request->sort())
			->paginate($request->perPage(50));

		return $this->collection($results->items());
	}

	/**
	 * Retrieve a summary of the filtered list of items. Totals, counts, etc.
	 *
	 * @param PagerRequest $request
	 * @return array
	 */
	public function summary(PagerRequest $request)
	{
		return $this->repo()->summary($request->filter());
	}

	/**
	 * Return the item details for a detail view / filling out defaults in an editable form / etc.
	 *
	 * @param $model
	 * @return JsonResponse|Response
	 */
	public function details($model)
	{
		if (!$model) {
			return response('Item not found', 404);
		}

		return $this->itemDetails($model);
	}

	/**
	 * Retrieve the data to populate the list of filters on the collection. Used for dropdowns, etc.
	 *
	 * @param PagerRequest $request
	 * @return array
	 */
	public function fieldOptions(PagerRequest $request)
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
			ErrorLog::logException('ERROR', $throwable);

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

	public function export(PagerRequest $request)
	{
		$export = $this->repo()->export($request->filter());

		return FileHelper::exportCsv($export);
	}
}
