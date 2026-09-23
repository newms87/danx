<?php

namespace Tests\Unit\Resources;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Newms87\Danx\Contracts\HasRecordDeletedAt;
use Newms87\Danx\Resources\ActionResource;
use Orchestra\Testbench\TestCase;

/**
 * SG-943 — `ActionResource::typedData()`'s `__deleted_at` decision now asks the MODEL, once,
 * instead of a resource re-deciding it per class. A tombstone applies per store key (one per
 * model+id), so two resources serializing the same model used to be able to disagree about
 * whether it is one — this is what a model-level `recordDeletedAt()`, declared via the
 * {@see HasRecordDeletedAt} contract, closes.
 *
 * These stubs are plain in-memory models (no migration, no persistence) — `typedData()` only
 * reads `getKey()`, `updated_at` and `deleted_at`/`recordDeletedAt()`, none of which need a
 * database row, mirroring `ApplyActionNullModelStubModel` in
 * `tests/Unit/Http/ActionControllerApplyActionNullModelTest.php`.
 */
class ActionResourceRecordDeletedAtTest extends TestCase
{
    public function test_a_model_with_no_record_deleted_at_method_falls_back_to_its_own_deleted_at(): void
    {
        $deletedAt = Carbon::parse('2026-09-01 12:00:00');
        $model     = (new RecordDeletedAtStubModelWithoutOverride)->forceFill([
            'id'         => 1,
            'deleted_at' => $deletedAt,
        ]);

        $data = RecordDeletedAtStubResourceWithoutOverride::make($model);

        $this->assertTrue($deletedAt->eq($data['__deleted_at']));
    }

    public function test_a_model_with_no_record_deleted_at_method_and_no_deleted_at_sends_null(): void
    {
        $model = (new RecordDeletedAtStubModelWithoutOverride)->forceFill(['id' => 2]);

        $data = RecordDeletedAtStubResourceWithoutOverride::make($model);

        $this->assertNull($data['__deleted_at']);
    }

    public function test_a_model_that_declares_record_deleted_at_always_null_overrides_a_real_deleted_at(): void
    {
        // A superseded reading / a flagged mistake: soft-deleted in the database, but the
        // model's own rule says this is never a tombstone as far as a client record store
        // is concerned.
        $model = (new RecordDeletedAtStubModelAlwaysLive)->forceFill([
            'id'         => 3,
            'deleted_at' => Carbon::parse('2026-09-01 12:00:00'),
        ]);

        $data = RecordDeletedAtStubResourceAlwaysLive::make($model);

        $this->assertNull($data['__deleted_at'], 'the model rule must win over the raw deleted_at column');
    }

    public function test_a_model_that_declares_record_deleted_at_conditionally_can_still_report_a_real_deletion(): void
    {
        // A genuinely deleted row (a reviewer-added record's own delete, a merge loser) must
        // still tombstone — only a model that explicitly opts a ROW out (like a flagged
        // mistake) gets null.
        $genuinelyDeleted = Carbon::parse('2026-09-10 08:30:00');
        $model            = (new RecordDeletedAtStubModelConditional)->forceFill([
            'id'         => 4,
            'deleted_at' => $genuinelyDeleted,
            'is_exempt'  => false,
        ]);

        $data = RecordDeletedAtStubResourceConditional::make($model);

        $this->assertTrue($genuinelyDeleted->eq($data['__deleted_at']));
    }

    public function test_a_model_that_declares_record_deleted_at_conditionally_can_report_a_row_as_never_deleted(): void
    {
        $model = (new RecordDeletedAtStubModelConditional)->forceFill([
            'id'         => 5,
            'deleted_at' => Carbon::parse('2026-09-10 08:30:00'),
            'is_exempt'  => true,
        ]);

        $data = RecordDeletedAtStubResourceConditional::make($model);

        $this->assertNull($data['__deleted_at']);
    }

    /**
     * SG-943 review (A1) — a model with a method NAMED `recordDeletedAt()` that does NOT declare
     * {@see HasRecordDeletedAt} must be ignored: the check is `instanceof`, never
     * `method_exists()`. A same-named method on an unrelated model (or a coincidental duck-type)
     * must never accidentally opt a model into this behaviour.
     */
    public function test_a_model_with_a_same_named_method_but_no_declared_contract_falls_back_to_deleted_at(): void
    {
        $deletedAt = Carbon::parse('2026-09-11 00:00:00');
        $model     = (new RecordDeletedAtStubModelDuckTyped)->forceFill([
            'id'         => 6,
            'deleted_at' => $deletedAt,
        ]);

        $data = RecordDeletedAtStubResourceDuckTyped::make($model);

        $this->assertTrue($deletedAt->eq($data['__deleted_at']), 'a same-named method with no declared contract must not be honoured');
    }
}

class RecordDeletedAtStubModelWithoutOverride extends Model
{
    protected $guarded = [];
    protected $casts   = ['deleted_at' => 'datetime'];
}

class RecordDeletedAtStubResourceWithoutOverride extends ActionResource
{
    public static function data(Model $model): array
    {
        return [];
    }
}

class RecordDeletedAtStubModelAlwaysLive extends Model implements HasRecordDeletedAt
{
    protected $guarded = [];
    protected $casts   = ['deleted_at' => 'datetime'];

    public function recordDeletedAt(): ?DateTimeInterface
    {
        return null;
    }
}

class RecordDeletedAtStubResourceAlwaysLive extends ActionResource
{
    public static function data(Model $model): array
    {
        return [];
    }
}

class RecordDeletedAtStubModelConditional extends Model implements HasRecordDeletedAt
{
    protected $guarded = [];
    protected $casts   = ['deleted_at' => 'datetime', 'is_exempt' => 'boolean'];

    public function recordDeletedAt(): ?DateTimeInterface
    {
        return $this->is_exempt ? null : $this->deleted_at;
    }
}

class RecordDeletedAtStubResourceConditional extends ActionResource
{
    public static function data(Model $model): array
    {
        return [];
    }
}

/** Deliberately does NOT `implements HasRecordDeletedAt` — see the duck-typed test above. */
class RecordDeletedAtStubModelDuckTyped extends Model
{
    protected $guarded = [];
    protected $casts   = ['deleted_at' => 'datetime'];

    public function recordDeletedAt(): ?DateTimeInterface
    {
        return null;
    }
}

class RecordDeletedAtStubResourceDuckTyped extends ActionResource
{
    public static function data(Model $model): array
    {
        return [];
    }
}
