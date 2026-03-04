<?php declare(strict_types=1);

namespace Company\Workpoint\Traits;

use Company\Workpoint\Models\WorkpointRecord;
use Company\Workpoint\Support\PeriodHelper;
use Company\Workpoint\WorkpointRecordService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasWorkpointRecords
{
    /**
     * Record a workpoint for this subject.
     *
     * @param  object|null  $target  Optional target (e.g. Task, Project).
     * @return \Company\Workpoint\Models\WorkpointRecord|null  The created record or null if not allowed.
     */
    public function recordWorkpoint(string $actionKey, object|null $target = null, array $payload = []): ?WorkpointRecord
    {
        return app(WorkpointRecordService::class)->record($this, $actionKey, $target, $payload);
    }

    /**
     * Workpoint records where this model is the subject.
     */
    public function workpointRecords(): MorphMany
    {
        return $this->morphMany(WorkpointRecord::class, 'subject');
    }

    /**
     * Get workpoint records for this subject in the given period (day|week|month|year).
     * Ordered by created_at descending.
     *
     * @return Collection<int, WorkpointRecord>
     */
    public function getWorkpointRecordsByPeriod(string $period): Collection
    {
        if (!PeriodHelper::isValidPeriod($period)) {
            return new Collection([]);
        }
        return $this->workpointRecords()
            ->inPeriod($period)
            ->orderByDesc('created_at')
            ->get();
    }
}
