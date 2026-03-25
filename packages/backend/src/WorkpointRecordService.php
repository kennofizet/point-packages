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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Kennofizet\PackagesCore\Core\Model\BaseModelActions;
use Carbon\Carbon;

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
        $caseConfig = $this->getCaseConfig($actionKey);
        if ($caseConfig === null) {
            return null;
        }

        $checkName = $caseConfig['check'] ?? 'none';
        $rule = $this->resolveRule($checkName);
        if ($rule === null || !$rule->allowed($subject, $target, $actionKey, $payload, $caseConfig)) {
            return null;
        }

        $pointsDelta = (int) ($caseConfig['points'] ?? 0);

        $record = WorkpointRecord::create([
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
        $periodKey = PeriodHelper::periodKey($period);

        $rows = WorkpointPeriodTotal::query()
            ->select('subject_type', 'subject_id', 'total_points')
            ->where('period_type', $period)
            ->where('period_key', $periodKey)
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

    private function getTopInPeriodFromRecords(string $period, int $limit): Collection
    {
        $range = PeriodHelper::range($period);
        $table = WorkpointRecord::getTableName();

        $rows = WorkpointRecord::query()
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
            if(isset($namesByKey[$key])){
                $item['subject_name'] = $namesByKey[$key];
                unset($item['subject_type']);
            }else{
                $item['subject_name'] = null;
            }
            return $item;
        });
    }

    /**
     * Get case config for an action key, merging default from config with zone override from DB.
     *
     * @return array{points: int, check: string, period?: string, cap?: int, descriptions: array<string, string>}|null
     */
    public function getCaseConfig(string $actionKey): ?array
    {
        $default = $this->config->get('workpoint_cases.' . $actionKey);
        if ($default === null || !is_array($default)) {
            return null;
        }
        $override = WorkpointZoneCase::query()
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
     * Merged rules for the current zone (default config + DB overrides), formatted for the rules API.
     *
     * @return array<int, array{key: string, points: int, check: string, period: string|null, cap: int|null, description: string}>
     */
    public function getMergedRulesForZone(string $lang = 'vi'): array
    {
        $cases = $this->config->get('workpoint_cases', []);
        $overrides = [];
        foreach (WorkpointZoneCase::query()->get() as $row) {
            $overrides[$row->case_key] = $row;
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
            }else{
                if($merged['points'] == 0 && !BaseModelActions::isManager()){
                    continue;
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
     * Zone is enforced by BaseModel global scope (request context); caller must authorize (e.g. canManageZoneOrServer).
     *
     * @param  array{points: int, check: string, period?: string|null, cap?: int|null, descriptions?: array<string, string>|null}  $data
     * @throws \InvalidArgumentException If case_key is not in config.
     */
    public function saveZoneCase(string $caseKey, array $data): void
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

        WorkpointZoneCase::query()->updateOrCreate(
            ['case_key' => $caseKey],
            $payload
        );
    }

    /**
     * Reset zone rules to default: delete all zone overrides for the current scoped zone and clone from workpoint_cases config.
     * Caller must authorize (e.g. canManageZoneOrServer for current zone).
     */
    public function resetZoneRulesToDefault(): void
    {
        $zoneId = BaseModelActions::currentUserZoneId();

        // Hard-delete only this zone. withoutGlobalScopes() drops zone + soft-delete scopes so we filter
        // zone_id explicitly; soft-deleted rows are included (no deleted_at filter) and forceDelete removes them.
        WorkpointZoneCase::withoutGlobalScopes()
            ->where('zone_id', $zoneId)
            ->forceDelete();

        $cases = $this->config->get('workpoint_cases', []);
        foreach ($cases as $caseKey => $case) {
            if (!is_array($case)) {
                continue;
            }
            WorkpointZoneCase::query()->create([
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

    /**
     * Configured subject class (polymorphic subject_type), e.g. App\Models\User.
     *
     * @return class-string
     */
    public function getSubjectClass(): string
    {
        $class = $this->config->get('workpoint.subject_class', 'App\\Models\\User');
        return is_string($class) && $class !== '' ? $class : 'App\\Models\\User';
    }

    /**
     * Paginated workpoint rows for a subject in the current zone and time window (period).
     * Returns only fields required by frontend history UI.
     *
     * @param  array<string, string>  $caseNameByKey
     * @return array{items: array<int, array{id: int, case_key: string, case_name: string, points_delta: int, created_at: string|null}>, next_cursor: string|null}
     */
    public function getHistoryForSubject(
        string $subjectType,
        int $subjectId,
        string $period,
        ?int $cursorId,
        array $caseNameByKey = [],
        ?int $perPage = null
    ): array {
        if (!PeriodHelper::isValidPeriod($period)) {
            $period = PeriodHelper::PERIOD_WEEK;
        }
        $perPage = $perPage ?? (int) $this->config->get('workpoint.history_per_page', 30);
        $perPage = min(max($perPage, 1), 100);

        $range = PeriodHelper::range($period);
        $table = WorkpointRecord::getTableName();

        $q = WorkpointRecord::query()
            ->where($table . '.subject_type', $subjectType)
            ->where($table . '.subject_id', $subjectId)
            ->whereBetween($table . '.created_at', [$range['start'], $range['end']])
            ->orderByDesc($table . '.id');

        if ($cursorId !== null) {
            $q->where($table . '.id', '<', $cursorId);
        }

        $rows = $q->limit($perPage + 1)->get([
            'id',
            'action_key',
            'points_delta',
            'created_at',
        ]);

        $hasMore = $rows->count() > $perPage;
        $slice = $hasMore ? $rows->take($perPage) : $rows;

        $nextCursor = null;
        if ($hasMore && $slice->isNotEmpty()) {
            $last = $slice->last();
            $nextCursor = (string) $last->id;
        }

        $items = $slice->map(function (WorkpointRecord $r) use ($caseNameByKey) {
            $caseKey = (string) $r->action_key;
            $caseName = $caseNameByKey[$caseKey] ?? $caseKey;
            return [
                'case_name' => $caseName,
                'points_delta' => (int) $r->points_delta,
                'created_at' => $r->created_at?->toIso8601String(),
            ];
        })->values()->all();

        return [
            'items' => $items,
            'next_cursor' => $nextCursor,
        ];
    }

    /**
     * Leaderboard position (1-based) for one subject in the given period within current zone.
     */
    public function getRankForSubjectInPeriod(string $subjectType, int $subjectId, string $period): ?int
    {
        if (!PeriodHelper::isValidPeriod($period)) {
            return null;
        }

        $myTotal = $this->getSubjectTotalInPeriod($subjectType, $subjectId, $period);

        $range = PeriodHelper::range($period);
        $table = WorkpointRecord::getTableName();

        $betterCount = WorkpointRecord::query()
            ->where($table . '.subject_type', $subjectType)
            ->whereBetween($table . '.created_at', [$range['start'], $range['end']])
            ->selectRaw($table . '.subject_id, SUM(' . $table . '.points_delta) as total_points')
            ->groupBy($table . '.subject_id')
            ->havingRaw('SUM(' . $table . '.points_delta) > ?', [$myTotal])
            ->count();

        return $betterCount + 1;
    }

    /**
     * Total points for subject in period (current zone).
     */
    public function getSubjectTotalInPeriod(string $subjectType, int $subjectId, string $period): int
    {
        if (!PeriodHelper::isValidPeriod($period)) {
            return 0;
        }
        $range = PeriodHelper::range($period);
        $table = WorkpointRecord::getTableName();

        return (int) WorkpointRecord::query()
            ->where($table . '.subject_type', $subjectType)
            ->where($table . '.subject_id', $subjectId)
            ->whereBetween($table . '.created_at', [$range['start'], $range['end']])
            ->sum('points_delta');
    }

    /**
     * Max total points achievable for this rule (for progress display).
     * - count_cap_per_period: points × cap (cap = max awards per period).
     * - first_time / first_time_per_period / first_time_per_target: one award → max = points.
     * - none / no cap on count: unlimited → null.
     */
    public function maxPointsForRuleDisplay(array $rule): ?int
    {
        $points = (int) ($rule['points'] ?? 0);
        $check = (string) ($rule['check'] ?? 'none');
        $cap = isset($rule['cap']) ? (int) $rule['cap'] : null;

        if ($check === 'count_cap_per_period') {
            if ($cap !== null && $cap > 0 && $points > 0) {
                return $points * $cap;
            }

            return null;
        }

        if (in_array($check, ['first_time', 'first_time_per_period', 'first_time_per_target'], true)) {
            return $points > 0 ? $points : null;
        }

        return null;
    }

    /**
     * Points earned today per action_key, merged with zone rules (description, cap).
     *
     * @return array<int, array{key: string, description: string, earned: int, cap: int|null, points: int, max_points: int|null, rule_period: string|null}>
     */
    public function getTodayProgressByRules(string $subjectType, int $subjectId, string $lang): array
    {
        $range = PeriodHelper::range(PeriodHelper::PERIOD_DAY);
        $table = WorkpointRecord::getTableName();

        $earned = WorkpointRecord::query()
            ->where($table . '.subject_type', $subjectType)
            ->where($table . '.subject_id', $subjectId)
            ->whereBetween($table . '.created_at', [$range['start'], $range['end']])
            ->selectRaw('action_key, SUM(points_delta) as total')
            ->groupBy('action_key')
            ->pluck('total', 'action_key');

        $rules = $this->getMergedRulesForZone($lang);
        $out = [];
        foreach ($rules as $rule) {
            $key = $rule['key'];

            if($rule['points'] == 0){
                continue;
            }
            
            $out[] = [
                'description' => $rule['description'],
                'earned' => (int) ($earned[$key] ?? 0),
                'max_points' => $this->maxPointsForRuleDisplay($rule)
            ];
        }

        return $out;
    }

    /**
     * Distinct subjects (same subject_class) in zone with cursor pagination on (last_at, subject_id).
     *
     * @return array{items: array<int, array{subject_type: string, subject_id: int, last_at: string|null, subject_name?: string|null}>, next_cursor: string|null}
     */
    public function listSubjectsInZoneCursor(?string $cursorEncoded, ?int $perPage = null): array
    {
        $perPage = $perPage ?? (int) $this->config->get('workpoint.admin_subjects_per_page', 30);
        $perPage = min(max($perPage, 1), 100);

        $subjectClass = $this->getSubjectClass();

        /** @var array{last_at: string, subject_id: int}|null $cursor */
        $cursor = null;
        if ($cursorEncoded !== null && $cursorEncoded !== '') {
            $decodedRaw = base64_decode($cursorEncoded, true);
            if ($decodedRaw !== false) {
                $decoded = json_decode($decodedRaw, true);
                if (
                    is_array($decoded)
                    && isset($decoded['last_at'], $decoded['subject_id'])
                    && is_string($decoded['last_at'])
                    && is_numeric($decoded['subject_id'])
                ) {
                    $cursor = [
                        'last_at' => $decoded['last_at'],
                        'subject_id' => (int) $decoded['subject_id'],
                    ];
                }
            }
        }

        // Inner subquery: latest record time per subject (zone scope from BaseModel).
        $latestBySubject = WorkpointRecord::query()
            ->selectRaw('subject_id, MAX(created_at) as last_at')
            ->where('subject_type', $subjectClass)
            ->groupBy('subject_id')
            ->toBase();

        // Outer query must not use model scopes because FROM is an alias (`subject_last`).
        $rowsQuery = DB::query()
            ->fromSub($latestBySubject, 'subject_last')
            ->select(['subject_id', 'last_at']);

        if ($cursor !== null) {
            $rowsQuery->where(function ($q) use ($cursor) {
                $q->where('last_at', '<', $cursor['last_at'])
                    ->orWhere(function ($q2) use ($cursor) {
                        $q2->where('last_at', '=', $cursor['last_at'])
                            ->where('subject_id', '<', $cursor['subject_id']);
                    });
            });
        }

        $rows = $rowsQuery
            ->orderByDesc('last_at')
            ->orderByDesc('subject_id')
            ->limit($perPage + 1)
            ->get();

        $hasMore = $rows->count() > $perPage;
        $slice = $hasMore ? $rows->take($perPage)->values() : $rows->values();

        $items = [];
        foreach ($slice as $row) {
            $items[] = [
                'subject_type' => $subjectClass,
                'subject_id' => (int) $row->subject_id
            ];
        }

        $coll = collect($items);
        $coll = $this->addSubjectNames($coll);

        $nextCursor = null;
        if ($hasMore && $slice->isNotEmpty()) {
            $last = $slice->last();
            $payload = [
                'last_at' => $last->last_at,
                'subject_id' => (int) $last->subject_id,
            ];
            $nextCursor = base64_encode(json_encode($payload));
        }

        return [
            'items' => $coll->values()->all(),
            'next_cursor' => $nextCursor,
        ];
    }

    /**
     * Bundle for history detail (manager or self): history slice + ranks + today progress.
     *
     * @return array<string, mixed>
     */
    public function buildUserHistoryDetail(
        string $subjectType,
        int $subjectId,
        string $historyPeriod,
        ?int $historyCursorId,
        string $lang
    ): array {
        $summary = $this->buildUserHistorySummary($subjectType, $subjectId, $lang);
        $history = $this->buildUserHistoryLogs($subjectType, $subjectId, $historyPeriod, $historyCursorId, $lang);
        return array_merge($summary, $history);
    }

    /**
     * Summary for history screen (no logs): totals + ranks + today progress.
     *
     * @return array{totals: array<string, int>, ranks: array<string, int|null>, today_by_rule: array<int, array{key: string, description: string, earned: int, cap: int|null, points: int, max_points: int|null, rule_period: string|null}>}
     */
    public function buildUserHistorySummary(
        string $subjectType,
        int $subjectId,
        string $lang
    ): array {
        return [
            'totals' => [
                'day' => $this->getSubjectTotalInPeriod($subjectType, $subjectId, PeriodHelper::PERIOD_DAY),
                'week' => $this->getSubjectTotalInPeriod($subjectType, $subjectId, PeriodHelper::PERIOD_WEEK),
                'month' => $this->getSubjectTotalInPeriod($subjectType, $subjectId, PeriodHelper::PERIOD_MONTH),
                'year' => $this->getSubjectTotalInPeriod($subjectType, $subjectId, PeriodHelper::PERIOD_YEAR),
            ],
            'ranks' => [
                'day' => $this->getRankForSubjectInPeriod($subjectType, $subjectId, PeriodHelper::PERIOD_DAY),
                'week' => $this->getRankForSubjectInPeriod($subjectType, $subjectId, PeriodHelper::PERIOD_WEEK),
                'month' => $this->getRankForSubjectInPeriod($subjectType, $subjectId, PeriodHelper::PERIOD_MONTH),
                'year' => $this->getRankForSubjectInPeriod($subjectType, $subjectId, PeriodHelper::PERIOD_YEAR),
            ],
            'today_by_rule' => $this->getTodayProgressByRules($subjectType, $subjectId, $lang),
        ];
    }

    /**
     * Paginated history logs for history screen only.
     *
     * @return array{period: string, items: array<int, array{id: int, case_key: string, case_name: string, points_delta: int, created_at: string|null}>, next_cursor: string|null}
     */
    public function buildUserHistoryLogs(
        string $subjectType,
        int $subjectId,
        string $historyPeriod,
        ?int $historyCursorId,
        string $lang
    ): array {
        $rules = $this->getMergedRulesForZone($lang);
        $caseNameByKey = [];
        foreach ($rules as $rule) {
            $key = (string) ($rule['key'] ?? '');
            if ($key !== '') {
                $caseNameByKey[$key] = (string) ($rule['description'] ?? $key);
            }
        }
        $history = $this->getHistoryForSubject($subjectType, $subjectId, $historyPeriod, $historyCursorId, $caseNameByKey);

        return array_merge(['period' => $historyPeriod], $history);
    }
}
