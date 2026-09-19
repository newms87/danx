<?php

namespace Tests\Unit\Events;

use Newms87\Danx\Events\ModelSavedEvent;
use Newms87\Danx\Models\Audit\AuditRequest;
use Orchestra\Testbench\TestCase;

/**
 * Pins WHICH thing the "already broadcast a created event" suppression is about: the model
 * INSTANCE, never the class-and-id pair.
 *
 * Saving one freshly-created instance twice in a request must announce it as new only once.
 * A DIFFERENT object that happens to carry the same id is a different row and must still
 * announce its own creation — that is the distinction the suppression used to get wrong.
 *
 * It got it wrong because the cache was an array keyed on `get_class($model) . ':' . $id`,
 * which cannot tell two objects apart. Ids do not repeat in production, so nothing there ever
 * noticed; they repeat freely within one long-lived PHPUnit process, which is where it
 * surfaced — a genuine INSERT announcing itself as an `updated`, in a consumer's test that
 * failed only inside large sweeps and passed whenever it was run alone. Keying on the object
 * (a WeakMap) answers the question that was actually being asked, and entries leave with the
 * model rather than accumulating for the life of the process.
 *
 * No persistence: `getEvent()` reads only `wasRecentlyCreated` and `exists`, both set here
 * directly, so the rule is exercised without a database.
 */
class ModelSavedEventCreateSuppressionTest extends TestCase
{
    private function freshlyCreated(int $id): AuditRequest
    {
        $model                     = new AuditRequest(['team_id' => 1, 'url' => '/test']);
        $model->id                 = $id;
        $model->exists             = true;
        $model->wasRecentlyCreated = true;

        return $model;
    }

    public function test_a_freshly_created_instance_announces_itself_once(): void
    {
        $model = $this->freshlyCreated(1);

        $this->assertSame(
            ModelSavedEvent::EVENT_CREATED,
            ModelSavedEvent::getEvent($model),
            'a freshly created model announces a create the first time',
        );

        ModelSavedEvent::markCreatedBroadcast($model);

        $this->assertSame(
            ModelSavedEvent::EVENT_UPDATED,
            ModelSavedEvent::getEvent($model),
            'saving the SAME instance again must not announce a second create',
        );
    }

    public function test_a_different_instance_reusing_an_id_still_announces_its_own_creation(): void
    {
        $first = $this->freshlyCreated(42);
        ModelSavedEvent::markCreatedBroadcast($first);

        // A different object, same id — a different row as far as this rule is concerned.
        $second = $this->freshlyCreated(42);

        $this->assertSame(
            ModelSavedEvent::EVENT_CREATED,
            ModelSavedEvent::getEvent($second),
            'a different instance carrying a reused id is a real creation and must say so; '
            . 'keying the suppression on class-and-id reported this as an update',
        );
    }

    public function test_an_instance_that_was_not_recently_created_is_never_suppressed_as_a_create(): void
    {
        $model                     = $this->freshlyCreated(7);
        $model->wasRecentlyCreated = false;

        $this->assertSame(
            ModelSavedEvent::EVENT_UPDATED,
            ModelSavedEvent::getEvent($model),
            're-loading an existing row never reaches the create path at all',
        );
    }
}
