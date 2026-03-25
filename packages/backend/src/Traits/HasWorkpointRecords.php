<?php declare(strict_types=1);

namespace Kennofizet\Workpoint\Traits;

use Kennofizet\Workpoint\Models\WorkpointRecord;
use Kennofizet\Workpoint\Support\PeriodHelper;
use Kennofizet\Workpoint\WorkpointRecordService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasWorkpointRecords
{
    /**
     * Record a workpoint for this subject.
     *
     * @param  object|null  $target  Optional target (e.g. Task, Project).
     * @return \Kennofizet\Workpoint\Models\WorkpointRecord|null  The created record or null if not allowed.
     */
    public function recordWorkpoint(string $actionKey, object|null $target = null, array $payload = []): ?WorkpointRecord
    {
        return app(WorkpointRecordService::class)->record($this, $actionKey, $target, $payload);
    }

    /**
     * Whether this subject already has a stored workpoint row for the action (and optional target) in the current zone.
     * Does not evaluate rule checks — only presence of a record. Use before calling {@see recordWorkpoint} or for UI gates.
     *
     * @param  object|null  $target  Same as {@see recordWorkpoint}: e.g. project/task model for per-target keys; omit for global keys.
     */
    public function hasWorkpointRecord(string $actionKey, object|null $target = null): bool
    {
        $query = $this->workpointRecords()
            ->where('action_key', $actionKey);

        if ($target === null) {
            $query->whereNull('target_type')->whereNull('target_id');
        } else {
            $query->where('target_type', $target::class)
                ->where('target_id', $target->getKey());
        }

        return $query->exists();
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
