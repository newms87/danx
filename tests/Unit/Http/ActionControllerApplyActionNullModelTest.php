<?php

namespace Tests\Unit\Http;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Newms87\Danx\Http\Controllers\ActionController;
use Newms87\Danx\Repositories\ActionRepository;
use Newms87\Danx\Requests\PagerRequest;
use Newms87\Danx\Resources\ActionResource;
use Orchestra\Testbench\TestCase;

/**
 * SG-780: `{id}/apply-action` hands `applyAction()` whatever `ActionRepository::instance()`
 * found for the route-bound id — `null` for an id that is not visible (deleted, another
 * team's, or soft-deleted without `?withTrashed=1`). Before this guard existed, that `null`
 * reached the repository's own `applyAction()`, which every real subclass writes assuming a
 * real model — a non-nullable typed handler parameter, or an untyped method call on it — so
 * PHP's own error, not the application, decided what happened next. The stub repo below
 * reproduces that exact shape: a non-nullable typed parameter that raises a `TypeError`
 * naming the class, method, argument position and file if it is ever reached with `null`.
 *
 * The guard must return a plain-language 404 BEFORE the repository is ever called for any
 * action other than `create` (the one action that legitimately runs with `$model === null` —
 * it makes a new record via the resource-level `apply-action` route, which has no `{id}`).
 */
class ActionControllerApplyActionNullModelTest extends TestCase
{
    public function test_apply_action_with_a_null_model_returns_a_plain_404_before_reaching_the_repository(): void
    {
        $response = $this->applyActionFor(null, 'update');

        $this->assertSame(404, $response->getStatusCode());

        $body = json_decode($response->getContent(), true);

        $this->assertTrue($body['error']);
        $this->assertSame('That record no longer exists.', $body['message']);

        // The whole point: no class name, method name, argument position or file path anywhere
        // in the body — the repository (which would raise exactly those) was never called.
        $this->assertArrayNotHasKey('class', $body);
        $this->assertArrayNotHasKey('file', $body);
        $this->assertArrayNotHasKey('trace', $body);
        $this->assertStringNotContainsString('TypeError', json_encode($body));
        $this->assertStringNotContainsString(ApplyActionNullModelStubRepository::class, json_encode($body));
    }

    public function test_apply_action_create_with_a_null_model_still_reaches_the_repository(): void
    {
        $response = $this->applyActionFor(null, 'create');

        // 'create' has no id to be missing — it must NOT be caught by the 404 guard. It reaches
        // the stub repository's 'create' arm, which does not touch the (absent) model at all.
        $this->assertSame(200, $response->getStatusCode());

        $body = json_decode($response->getContent(), true);
        $this->assertTrue($body['success']);
    }

    public function test_apply_action_with_a_real_model_still_reaches_the_repository(): void
    {
        $model = (new ApplyActionNullModelStubModel)->forceFill(['id' => 1, 'name' => 'Real']);

        $response = $this->applyActionFor($model, 'update');

        $this->assertSame(200, $response->getStatusCode());
    }

    private function applyActionFor(?Model $model, string $action)
    {
        $request = new PagerRequest(Request::create('/stub/apply-action', 'POST', [
            'action' => $action,
            'data'   => [],
        ]));

        return (new ApplyActionNullModelStubController)->applyAction($model, $request);
    }
}

class ApplyActionNullModelStubModel extends Model
{
    protected $guarded = [];
}

class ApplyActionNullModelStubResource extends ActionResource
{
    public static function data(Model $model): array
    {
        return ['name' => $model->name];
    }
}

class ApplyActionNullModelStubRepository extends ActionRepository
{
    public static string $model = ApplyActionNullModelStubModel::class;

    public function applyAction(string $action, Model|null|array $model = null, ?array $data = null)
    {
        return match ($action) {
            'create' => true,
            // A non-nullable typed parameter, exactly like every real repository's own typed
            // handlers — this is what used to raise the raw TypeError when $model was null.
            'update' => $this->updateStub($model, $data),
            default => parent::applyAction($action, $model, $data),
        };
    }

    private function updateStub(ApplyActionNullModelStubModel $model, ?array $data): ApplyActionNullModelStubModel
    {
        return $model->forceFill($data ?? []);
    }
}

class ApplyActionNullModelStubController extends ActionController
{
    public static ?string $repo = ApplyActionNullModelStubRepository::class;

    public static ?string $resource = ApplyActionNullModelStubResource::class;
}
