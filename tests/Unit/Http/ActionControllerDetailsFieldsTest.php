<?php

namespace Tests\Unit\Http;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Newms87\Danx\Http\Controllers\ActionController;
use Newms87\Danx\Repositories\ActionRepository;
use Newms87\Danx\Resources\ActionResource;
use Orchestra\Testbench\TestCase;

/**
 * `GET {id}/details` with no `fields` must answer with the resource's DEFAULT includes.
 *
 * WHY: {@see ActionResource::details()} takes `?array $includeFields = null` and reads null
 * as "use this resource's defaults" — `['*' => true]` in the base class, a hand-picked set
 * wherever a resource overrides it (a workflow definition's `nodes` and `connections`, for
 * one). An array, even an empty one, is an explicit selection that replaces those defaults.
 *
 * Since 12e1b5e (1.2.95) the controller turned an ABSENT `fields` into `[]`, so every
 * details call that named no fields received only the scalar columns and none of the
 * relations its resource declares by default. The admin workflow canvas asks for details
 * without fields, so it loaded with no steps (SG-481).
 *
 * Exercised with an in-memory model and no database: details() reads nothing but the
 * request and the resource.
 */
class ActionControllerDetailsFieldsTest extends TestCase
{
    public function test_details_with_no_fields_requested_applies_the_resource_default_includes(): void
    {
        $response = $this->detailsFor([]);

        $this->assertSame(['one', 'two'], $response['children'] ?? null, 'the resource\'s default include was dropped');
        $this->assertSame('Parent', $response['name']);
    }

    public function test_details_with_json_fields_requested_returns_exactly_that_selection(): void
    {
        $response = $this->detailsFor(['fields' => json_encode(['name' => true])]);

        $this->assertArrayNotHasKey('children', $response, 'an explicit selection replaces the defaults');
        $this->assertSame('Parent', $response['name']);
    }

    public function test_details_with_an_explicitly_empty_selection_includes_no_relations(): void
    {
        $response = $this->detailsFor(['fields' => '{}']);

        $this->assertArrayNotHasKey('children', $response, 'an empty selection is still a selection, not "use the defaults"');
        $this->assertSame('Parent', $response['name']);
    }

    public function test_details_with_fields_as_a_query_array_honors_the_selection(): void
    {
        $response = $this->detailsFor(['fields' => ['name' => true]]);

        $this->assertArrayNotHasKey('children', $response);
        $this->assertSame('Parent', $response['name']);
    }

    private function detailsFor(array $query): array
    {
        $this->app->instance('request', Request::create('/stub/1/details', 'GET', $query));

        $model = (new DetailsFieldsStubModel)->forceFill(['id' => 1, 'name' => 'Parent']);

        return (new DetailsFieldsStubController)->details($model);
    }
}

class DetailsFieldsStubModel extends Model
{
    protected $guarded = [];
}

class DetailsFieldsStubResource extends ActionResource
{
    public static function data(Model $model): array
    {
        return [
            'name'     => $model->name,
            'children' => fn() => ['one', 'two'],
        ];
    }

    public static function details(Model $model, ?array $includeFields = null): array
    {
        return static::make($model, $includeFields ?? ['children' => true]);
    }
}

class DetailsFieldsStubController extends ActionController
{
    public static ?string $repo = ActionRepository::class;

    public static ?string $resource = DetailsFieldsStubResource::class;
}
