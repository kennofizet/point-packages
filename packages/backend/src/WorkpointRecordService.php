<?php declare(strict_types=1);

namespace Kennofizet\Workpoint;

use Kennofizet\Workpoint\Contracts\AfterWorkpointRecordedListener;
use Kennofizet\Workpoint\Contracts\CheckRuleInterface;
use Kennofizet\Workpoint\Events\WorkpointRecorded;
use Kennofizet\Workpoint\Models\WorkpointPeriodTotal;
use Kennofizet\Workpoint\Models\WorkpointRecord;
use Kennofizet\Workpoint\Models\WorkpointZoneCase;
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
        $zoneId = BaseModelActions::currentUserZoneId();
        $caseConfig = $this->getCaseConfig($actionKey, $zoneId);
        if ($caseConfig === null) {
            return null;
        }

        $checkName = $caseConfig['check'] ?? 'none';
        $rule = $this->resolveRule($checkName);
        if ($rule === null || !$rule->allowed($subject, $target, $actionKey, $payload, $caseConfig, $zoneId)) {
            return null;
        }

        $pointsDelta = (int) ($caseConfig['points'] ?? 0);

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

        $items = $rows->map(fn ($row) => [
            'subject_type' => $row->subject_type,
            'subject_id' => (int) $row->subject_id,
            'total_points' => (int) $row->total_points,
        ]);

        return $this->addSubjectNames($items);
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

        $items = $rows->map(fn ($row) => [
            'subject_type' => $row->subject_type,
            'subject_id' => (int) $row->subject_id,
            'total_points' => (int) $row->total_points,
        ]);

        return $this->addSubjectNames($items);
    }

    /**
     * When workpoint.subject_name_col is set, resolve subject by type/id and add subject_name to each item.
     *
     * @param  Collection<int, array{subject_type: string, subject_id: int, total_points: int}>  $items
     * @return Collection<int, array{subject_type: string, subject_id: int, total_points: int, subject_name?: string}>
     */
    private function addSubjectNames(Collection $items): Collection
    {
        $nameCol = $this->config->get('workpoint.subject_name_col');
        if ($nameCol === null || $nameCol === '') {
            return $items;
        }

        $byType = $items->groupBy('subject_type');

        $namesByKey = [];
        foreach ($byType as $subjectType => $typeItems) {
            if (! is_string($subjectType) || ! class_exists($subjectType)) {
                continue;
            }
            $ids = $typeItems->pluck('subject_id')->unique()->values()->all();
            if ($ids === []) {
                continue;
            }
            try {
                $keyName = $subjectType::query()->getModel()->getKeyName();
                $models = $subjectType::query()->whereIn($keyName, $ids)->get([$keyName, $nameCol]);
            } catch (\Throwable) {
                continue;
            }
            foreach ($models as $model) {
                $id = $model->getKey();
                $namesByKey[$subjectType . '|' . $id] = $model->getAttribute($nameCol);
            }
        }

        return $items->map(function (array $item) use ($namesByKey) {
            $key = $item['subject_type'] . '|' . $item['subject_id'];
            $item['subject_name'] = $namesByKey[$key] ?? null;
            return $item;
        });
    }

    /**
     * Get case config for an action key, merging default from config with zone override from DB.
     *
     * @return array{points: int, check: string, period?: string, cap?: int, descriptions: array<string, string>}|null
     */
    public function getCaseConfig(string $actionKey, ?int $zoneId = null): ?array
    {
        $default = $this->config->get('workpoint_cases.' . $actionKey);
        if ($default === null || !is_array($default)) {
            return null;
        }
        if ($zoneId === null) {
            return $default;
        }
        $override = WorkpointZoneCase::where('zone_id', $zoneId)
            ->where('case_key', $actionKey)
            ->first();
        if ($override === null) {
            return $default;
        }
        $merged = $default;
        foreach (['points', 'check', 'period', 'cap'] as $key) {
            $v = $override->getAttribute($key);
            if ($v !== null) {
                $merged[$key] = $v;
            }
        }
        $descDefault = $merged['descriptions'] ?? [];
        $descOverride = $override->descriptions;
        if (is_array($descOverride) && $descOverride !== []) {
            $merged['descriptions'] = array_merge($descDefault, $descOverride);
        }
        return $merged;
    }

    /**
     * Get merged rules for a zone (default config + zone overrides), formatted for the rules API.
     *
     * @param  int|null  $zoneId
     * @param  string  $lang  Language code for description (e.g. 'vi', 'en').
     * @return array<int, array{key: string, points: int, check: string, period: string|null, cap: int|null, description: string}>
     */
    public function getMergedRulesForZone(?int $zoneId, string $lang = 'vi'): array
    {
        $cases = $this->config->get('workpoint_cases', []);
        $overrides = [];
        if ($zoneId !== null) {
            $rows = WorkpointZoneCase::where('zone_id', $zoneId)->get();
            foreach ($rows as $row) {
                $overrides[$row->case_key] = $row;
            }
        }

        $list = [];
        foreach ($cases as $key => $case) {
            if (!is_array($case)) {
                continue;
            }
            $merged = $case;
            if (isset($overrides[$key])) {
                $o = $overrides[$key];
                foreach (['points', 'check', 'period', 'cap'] as $k) {
                    $v = $o->getAttribute($k);
                    if ($v !== null) {
                        $merged[$k] = $v;
                    }
                }
                if (is_array($o->descriptions) && $o->descriptions !== []) {
                    $merged['descriptions'] = array_merge($merged['descriptions'] ?? [], $o->descriptions);
                }
            }
            $descriptions = $merged['descriptions'] ?? [];
            $description = $descriptions[$lang] ?? $descriptions['en'] ?? $descriptions['vi'] ?? (string) $key;

            $list[] = [
                'key' => $key,
                'points' => (int) ($merged['points'] ?? 0),
                'check' => (string) ($merged['check'] ?? 'none'),
                'period' => isset($merged['period']) ? (string) $merged['period'] : null,
                'cap' => isset($merged['cap']) ? (int) $merged['cap'] : null,
                'description' => $description,
            ];
        }

        return $list;
    }

    /**
     * Save or update one zone case override. Case key must exist in workpoint_cases config.
     *
     * @param  array{points: int, check: string, period?: string|null, cap?: int|null, descriptions?: array<string, string>|null}  $data
     * @throws \InvalidArgumentException If case_key is not in config.
     */
    public function saveZoneCase(int $zoneId, string $caseKey, array $data): void
    {
        $defaultCases = $this->config->get('workpoint_cases', []);
        if (!isset($defaultCases[$caseKey]) || !is_array($defaultCases[$caseKey])) {
            throw new \InvalidArgumentException('Invalid case_key');
        }

        $payload = array_filter([
            'points' => (int) ($data['points'] ?? 0),
            'check' => is_string($data['check'] ?? null) ? $data['check'] : 'none',
            'period' => isset($data['period']) && $data['period'] !== '' ? (string) $data['period'] : null,
            'cap' => isset($data['cap']) && $data['cap'] !== '' ? (int) $data['cap'] : null,
            'descriptions' => is_array($data['descriptions'] ?? null) ? $data['descriptions'] : null,
        ], fn ($v) => $v !== null);

        WorkpointZoneCase::updateOrCreate(
            ['zone_id' => $zoneId, 'case_key' => $caseKey],
            $payload
        );
    }

    /**
     * Reset zone rules to default: delete all zone overrides and clone from workpoint_cases config.
     */
    public function resetZoneRulesToDefault(int $zoneId): void
    {
        WorkpointZoneCase::where('zone_id', $zoneId)->delete();

        $cases = $this->config->get('workpoint_cases', []);
        foreach ($cases as $caseKey => $case) {
            if (!is_array($case)) {
                continue;
            }
            WorkpointZoneCase::create([
                'zone_id' => $zoneId,
                'case_key' => $caseKey,
                'points' => (int) ($case['points'] ?? 0),
                'check' => (string) ($case['check'] ?? 'none'),
                'period' => isset($case['period']) ? (string) $case['period'] : null,
                'cap' => isset($case['cap']) ? (int) $case['cap'] : null,
                'descriptions' => $case['descriptions'] ?? null,
            ]);
        }
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
