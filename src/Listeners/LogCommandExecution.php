<?php

namespace Newms87\Danx\Listeners;

use Newms87\Danx\Audit\AuditDriver;

/**
 * Records the console command being run on the current audit request.
 */
class LogCommandExecution
{
    /**
     * Queue-worker commands record nothing. Each job a worker runs gets an audit request of its
     * own ({@see ReleaseAuditRequestAtJobBoundary}), so the worker command has nothing to claim —
     * and on a warm Vapor queue Lambda every invocation starts `vapor:work` while the previous
     * invocation's job audit request may still be current (a failed job keeps it until the next job
     * starts). Recording there overwrote that job's own `request` with `{"command":"vapor:work"}`,
     * and with nothing current it would create an empty audit request per invocation (SG-488).
     */
    const array QUEUE_WORKER_COMMANDS = ['vapor:work', 'queue:work', 'horizon:work'];

    public function handle(object $event): void
    {
        $commandName = $event->command;

        if (in_array($commandName, self::QUEUE_WORKER_COMMANDS, true)) {
            return;
        }

        $params = $event->input->getArguments();

        AuditDriver::getAuditRequest()?->update([
            'request' => [
                'command' => $commandName,
                'params'  => $params,
            ],
        ]);
    }
}
