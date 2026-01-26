<?php

namespace Newms87\Danx;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Newms87\Danx\Console\Commands\AuditDebugCommand;
use Newms87\Danx\Console\Commands\DanxLinkCommand;
use Newms87\Danx\Console\Commands\FixPermissions;
use Newms87\Danx\Console\Commands\SyncDirtyJobsCommand;
use Newms87\Danx\Console\Commands\TestHeartbeatCommand;
use Newms87\Danx\Console\Commands\TranscodeCleanCommand;
use Newms87\Danx\Console\Commands\VaporDecryptCommand;
use Newms87\Danx\Console\Commands\VaporEncryptCommand;
use Newms87\Danx\Http\Controllers\ApiLogController;
use Newms87\Danx\Http\Controllers\AuditRequestController;
use Newms87\Danx\Http\Controllers\JobDispatchController;
use Newms87\Danx\Http\Controllers\StoredFileController;
use Newms87\Danx\Http\Routes\ActionRoute;
use Newms87\Danx\Listeners\LogCommandExecution;
use Newms87\Danx\Models\Audit\AuditRequest;
use Newms87\Danx\Support\SignalHandler;
use Newms87\Danx\Traits\HasRelationCountersTrait;

require_once __DIR__ . '/../bootstrap/helpers.php';

class DanxServiceProvider extends ServiceProvider
{
    public function boot()
    {
        Event::listen(CommandStarting::class, LogCommandExecution::class);

        SignalHandler::register();

        $this->mergeConfigFrom(__DIR__ . '/../config/danx.php', 'danx');

        $this->publishesMigrations([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ]);

        $this->publishes([
            __DIR__ . '/../.tinkerwell'     => base_path('.tinkerwell'),
            __DIR__ . '/../config/danx.php' => config_path('danx.php'),
        ]);

        $this->publishes([
            __DIR__ . '/../database/factories' => database_path('factories'),
        ], 'factories');

        if ($this->app->runningInConsole()) {
            $this->commands([
                AuditDebugCommand::class,
                DanxLinkCommand::class,
                FixPermissions::class,
                SyncDirtyJobsCommand::class,
                TestHeartbeatCommand::class,
                TranscodeCleanCommand::class,
                VaporDecryptCommand::class,
                VaporEncryptCommand::class,
            ]);
        }

        $this->registerDanxRelationCounters();
    }

    /**
     * Register relation counters for danx models that use HasRelationCountersTrait.
     * This ensures counter fields are updated when related records are created/deleted.
     */
    private function registerDanxRelationCounters(): void
    {
        $danxModelsWithCounters = [
            AuditRequest::class,
        ];

        foreach ($danxModelsWithCounters as $model) {
            if (in_array(HasRelationCountersTrait::class, class_uses_recursive($model))) {
                $model::registerRelationshipCounters();
            }
        }
    }

    public function register()
    {
    }

    /**
     * Register audit-related routes (AuditRequest, ApiLog, JobDispatch)
     * Call this method in your app's routes/api.php within the api middleware group
     */
    public static function registerAuditRoutes(): void
    {
        ActionRoute::routes('audit-requests', new AuditRequestController);
        ActionRoute::routes('api-logs', new ApiLogController);
        ActionRoute::routes('job-dispatches', new JobDispatchController);
        ActionRoute::routes('stored-files', new StoredFileController);
    }
}
