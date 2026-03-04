<?php declare(strict_types=1);

namespace Company\Workpoint;

use Company\Workpoint\Models\WorkpointRecord;

class ForSubjectFluent
{
    public function __construct(
        private readonly WorkpointRecordService $service,
        private readonly string $subjectType,
        private readonly int $subjectId
    ) {
    }

    public function record(
        string $actionKey,
        object|null $target = null,
        array $payload = []
    ): ?WorkpointRecord {
        $subject = $this->subjectType::find($this->subjectId);
        if ($subject === null) {
            return null;
        }
        return $this->service->record($subject, $actionKey, $target, $payload);
    }
}
