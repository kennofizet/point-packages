<?php declare(strict_types=1);

namespace Kennofizet\Workpoint\Contracts;

use Kennofizet\Workpoint\Models\WorkpointRecord;

interface AfterWorkpointRecordedListener
{
    /**
     * Called after a workpoint is recorded (and after WorkpointRecorded event is dispatched).
     * Use for e.g. updating coin balance, sending notification, logging.
     * Extra runtime attributes are available on $record:
     * - rate_convert: season rate convert (default 1)
     * - converted_output: points_delta * rate_convert
     */
    public function handle(WorkpointRecord $record): void;
}
