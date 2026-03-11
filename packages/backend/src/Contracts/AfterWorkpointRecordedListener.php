<?php declare(strict_types=1);

namespace Kennofizet\Workpoint\Contracts;

use Kennofizet\Workpoint\Models\WorkpointRecord;

interface AfterWorkpointRecordedListener
{
    /**
     * Called after a workpoint is recorded (and after WorkpointRecorded event is dispatched).
     * Use for e.g. updating coin balance, sending notification, logging.
     */
    public function handle(WorkpointRecord $record): void;
}
