<?php declare(strict_types=1);

namespace Kennofizet\Workpoint;

use Kennofizet\Workpoint\Contracts\AfterWorkpointRecordedListener;
use Kennofizet\Workpoint\Contracts\CheckRuleInterface;
use Kennofizet\Workpoint\Events\WorkpointRecorded;
use Kennofizet\Workpoint\Models\WorkpointPeriodTotal;
use Kennofizet\Workpoint\Models\WorkpointRecord;
use Kennofizet\Workpoint\Services\PeriodTotalsSync;
use Kennofizet\Workpoint\Support\PeriodHelper;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Kennofizet\PackagesCore\Core\Model\BaseModelActions;

class WorkpointRecordService
{
    /** @var array<string, CheckRuleInterface> */
    private array $ruleCache = [];

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly PeriodTotalsSync $periodTotalsSync
    ) {
    }

    public function record(
        object $subject,
        string $actionKey,
        object|null $target = null,
        array $payload = []
    ): ?WorkpointRecord {
        $caseConfig = $this->config->get('workpoint_cases.' . $actionKey);
        if ($caseConfig === null) {
            return null;
        }

        $checkName = $caseConfig['check'] ?? 'none';
        $rule = $this->resolveRule($checkName);
        if ($rule === null || !$rule->allowed($subject, $target, $actionKey, $payload, $caseConfig)) {
            return null;
        }

        $pointsDelta = (int) ($caseConfig['points'] ?? 0);
        $zoneId = BaseModelActions::currentUserZoneId();

        $record = WorkpointRecord::create([
            'zone_id' => $zoneId,
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'target_type' => $target ? $target::class : null,
            'target_id' => $target ? $target->getKey() : null,
            'action_key' => $actionKey,
            'points_delta' => $pointsDelta,
            'payload' => $payload ?: null,
        ]);

        $record->load(['subject', 'target']);

        $eventClass = $this->config->get('workpoint.event_class', WorkpointRecorded::class);
        event(new $eventClass($record));

        $this->runAfterRecordListeners($record);

        if ($this->config->get('workpoint.use_period_totals_table', false)) {
            $this->periodTotalsSync->syncRecord($record);
        }

        return $record;
    }

    /**
     * Record by subject type and id (resolves subject from DB).
     *
     * @param  class-string  $subjectType  e.g. App\Models\User
     * @param  int  $subjectId
     * @return ForSubjectFluent
     */
    public function forSubject(string $subjectType, int $subjectId): ForSubjectFluent
    {
        return new ForSubjectFluent($this, $subjectType, $subjectId);
    }

    /**
     * Get top subjects by total points in a period (scoped by current zone).
     *
     * @param  string  $period  'day'|'week'|'month'|'year'
     * @param  int  $limit
     * @return Collection<int, array{subject_type: string, subject_id: int, total_points: int}>
     */
    /**
     * Get top subjects by total points in a period. Uses workpoint_period_totals when
     * use_period_totals_table is true (scalable); otherwise aggregates workpoint_records.
     */
    public function getTopInPeriod(string $period, int $limit = 10): Collection
    {
        if ($this->config->get('workpoint.use_period_totals_table', false)) {
            return $this->getTopInPeriodFromTotals($period, $limit);
        }
        return $this->getTopInPeriodFromRecords($period, $limit);
    }

    private function getTopInPeriodFromTotals(string $period, int $limit): Collection
    {
        $zoneId = BaseModelActions::currentUserZoneId();
        $periodKey = PeriodHelper::periodKey($period);
        $table = WorkpointPeriodTotal::getTableName();

        $query = DB::table($table)
            ->where('period_type', $period)
            ->where('period_key', $periodKey)
            ->orderByDesc('total_points')
            ->limit($limit);

        if ($zoneId !== null) {
            $query->where('zone_id', $zoneId);
        }

        $rows = $query->get(['subject_type', 'subject_id', 'total_points']);

        return $rows->map(fn ($row) => [
            'subject_type' => $row->subject_type,
            'subject_id' => (int) $row->subject_id,
            'total_points' => (int) $row->total_points,
        ]);
    }

    private function getTopInPeriodFromRecords(string $period, int $limit): Collection
    {
        $range = PeriodHelper::range($period);
        $table = WorkpointRecord::getTableName();

        $rows = WorkpointRecord::query()
            ->inCurrentZone()
            ->whereBetween($table . '.created_at', [$range['start'], $range['end']])
            ->selectRaw('subject_type, subject_id, SUM(points_delta) as total_points')
            ->groupBy('subject_type', 'subject_id')
            ->orderByDesc('total_points')
            ->limit($limit)
            ->get();

        return $rows->map(fn ($row) => [
            'subject_type' => $row->subject_type,
            'subject_id' => (int) $row->subject_id,
            'total_points' => (int) $row->total_points,
        ]);
    }

    private function resolveRule(string $checkName): ?CheckRuleInterface
    {
        if (isset($this->ruleCache[$checkName])) {
            return $this->ruleCache[$checkName];
        }
        $map = $this->config->get('workpoint.rules', []);
        $class = $map[$checkName] ?? null;
        if ($class === null) {
            return null;
        }
        $rule = app()->make($class);
        if (!$rule instanceof CheckRuleInterface) {
            return null;
        }
        $this->ruleCache[$checkName] = $rule;
        return $rule;
    }

    private function runAfterRecordListeners(WorkpointRecord $record): void
    {
        $listeners = $this->config->get('workpoint.after_record_listeners', []);
        if (!is_array($listeners)) {
            return;
        }
        foreach ($listeners as $class) {
            if (!is_string($class) || !class_exists($class)) {
                continue;
            }
            $listener = app()->make($class);
            if ($listener instanceof AfterWorkpointRecordedListener) {
                $listener->handle($record);
            }
        }
    }
}
