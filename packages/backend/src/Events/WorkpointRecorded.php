<?php declare(strict_types=1);

namespace Company\Workpoint\Events;

use Company\Workpoint\Models\WorkpointRecord;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WorkpointRecorded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly WorkpointRecord $record
    ) {
    }

    public function getSubject()
    {
        return $this->record->subject;
    }

    public function getActionKey(): string
    {
        return $this->record->action_key;
    }

    public function getPointsDelta(): int
    {
        return $this->record->points_delta;
    }
}
