<?php

namespace Tests\Unit\Models\Audit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Newms87\Danx\DanxServiceProvider;
use Newms87\Danx\Models\Audit\Audit;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * A minimal stand-in for a consuming app's own user model that does NOT use SoftDeletes —
 * e.g. gpt-manager's real \App\Models\User, the concrete case SG-836 reproduced against.
 */
class AuditUserRelationTestUser extends Model
{
    protected $table = 'users';

    protected $guarded = [];
}

/**
 * A stand-in for a consuming app's user model that DOES use SoftDeletes — proves the SG-836
 * fix does not silently drop withTrashed() for callers whose related model genuinely supports
 * it (the card's own stated regression risk).
 */
class AuditUserRelationTestSoftDeleteUser extends Model
{
    use SoftDeletes;

    protected $table = 'soft_delete_users';

    protected $guarded = [];
}

/**
 * SG-836 — \Newms87\Danx\Models\Audit\Audit::user() called ->withTrashed() unconditionally on
 * its belongsTo(), a query-builder macro that only exists when the related model uses
 * SoftDeletes. gpt-manager's App\Models\User does not, so loading an audit row's author threw
 * BadMethodCallException (surfaced as RelationNotFoundException through eager-load resolution)
 * for every consumer — reproduced live during SG-831, and again here without any app-specific
 * scaffolding, against a bare Orchestra\Testbench app that mirrors a consuming app's own
 * auth.providers.users.model configuration point.
 */
class AuditUserRelationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [DanxServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email');
            $table->timestamps();
        });

        Schema::create('soft_delete_users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('audits', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('audit_request_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('event');
            $table->string('auditable_type');
            $table->char('auditable_id');
            $table->json('old_values');
            $table->json('new_values');
            $table->text('tags')->nullable();
            $table->timestamps(3);
        });
    }

    #[Test]
    public function the_relation_loads_without_throwing_when_the_related_model_lacks_soft_deletes(): void
    {
        Config::set('auth.providers.users.model', AuditUserRelationTestUser::class);

        $user  = AuditUserRelationTestUser::create(['name' => 'No SoftDeletes', 'email' => 'nosd@example.com']);
        $audit = Audit::create([
            'event'          => 'updated',
            'auditable_type' => 'App\Models\TeamObject\TeamObject',
            'auditable_id'   => '1',
            'old_values'     => [],
            'new_values'     => [],
            'user_id'        => $user->id,
        ]);

        // Direct relation access — this exact call threw BadMethodCallException before the
        // SG-836 fix (reproduced live against gpt-manager's real data before this test was
        // written).
        $loaded = $audit->user;
        $this->assertNotNull($loaded);
        $this->assertSame($user->id, $loaded->id);

        // Eager load — the path TeamObjectStageHistoryService::historyFor() (SG-836) now uses
        // instead of its old hand-rolled user_id cache; before the fix this threw
        // RelationNotFoundException, Eloquent's own wrapper around the relation macro's
        // BadMethodCallException.
        $eager = Audit::with('user')->find($audit->id);
        $this->assertNotNull($eager->user);
        $this->assertSame($user->id, $eager->user->id);
    }

    #[Test]
    public function with_trashed_still_applies_when_the_related_model_uses_soft_deletes(): void
    {
        Config::set('auth.providers.users.model', AuditUserRelationTestSoftDeleteUser::class);

        $user  = AuditUserRelationTestSoftDeleteUser::create(['name' => 'Soft Deletes', 'email' => 'sd@example.com']);
        $audit = Audit::create([
            'event'          => 'updated',
            'auditable_type' => 'App\Models\TeamObject\TeamObject',
            'auditable_id'   => '1',
            'old_values'     => [],
            'new_values'     => [],
            'user_id'        => $user->id,
        ]);

        $user->delete();
        $this->assertTrue($user->trashed());

        // Without withTrashed() this would resolve to null — the regression the SG-836 fix
        // must not introduce for a related model that genuinely supports soft deletion.
        $loaded = $audit->fresh()->user;
        $this->assertNotNull($loaded);
        $this->assertSame($user->id, $loaded->id);
        $this->assertNotNull($loaded->deleted_at);
    }
}
