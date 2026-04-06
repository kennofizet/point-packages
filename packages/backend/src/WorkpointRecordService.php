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
use Kennofizet\PackagesCore\Models\User;

class WorkpointRecordService
{
    /** @var array<string, CheckRuleInterface> */
    private array $ruleCache = [];

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly PeriodTotalsSync $periodTotalsSync
    ) {
    }

    /**
     * @param  object  $userEntity  Polymorphic user entity to persist and pass to rules/relations; queries use user_id only.
     */
    public function record(
        object $userEntity,
        string $actionKey,
        object|null $target = null,
        array $payload = [],
        ?array $zoneIds = null,
        ?int $userId = null,
    ): ?WorkpointRecord {
        if ($userId === null || $userId <= 0) {
            return null;
        }

        $resolvedZoneIds = $zoneIds ?? [];
        if ($resolvedZoneIds === []) {
            return null;
        }

        $firstRecord = null;
        foreach ($resolvedZoneIds as $zoneId) {
            if (!is_int($zoneId) || $zoneId <= 0) {
                continue;
            }
            $record = $this->recordInZone($userEntity, $userId, $actionKey, $target, $payload, $zoneId);
            if ($record !== null && $firstRecord === null) {
                $firstRecord = $record;
            }
        }

        return $firstRecord;
    }

    /**
     * @param  int|null  $zoneId  Explicit zone for record/check. Null keeps package-core default behavior.
     */
    private function recordInZone(
        object $userEntity,
        int $userId,
        string $actionKey,
        object|null $target,
        array $payload,
        ?int $zoneId
    ): ?WorkpointRecord {
        $caseConfig = $this->getCaseConfig($actionKey, $zoneId);
        if ($caseConfig === null) {
            return null;
        }
        $caseConfig['user_id'] = $userId;

        $checkName = $caseConfig['check'] ?? 'none';
        $rule = $this->resolveRule($checkName);
        if ($rule === null || !$rule->allowed($userEntity, $target, $actionKey, $payload, $caseConfig, $zoneId)) {
            return null;
        }

        if (!$this->isWithinLimitPeriod($userId, $actionKey, $zoneId, $caseConfig)) {
            return null;
        }

        $pointsDelta = (int) ($caseConfig['points'] ?? 0);

        $record = WorkpointRecord::query()->create([
            'user_id' => $userId,
            'subject_type' => $userEntity::class,
            'subject_id' => $userEntity->getKey(),
            'target_type' => $target ? $target::class : null,
            'target_id' => $target ? $target->getKey() : null,
            'action_key' => $actionKey,
            'points_delta' => $pointsDelta,
            'payload' => $payload ?: null,
            'zone_id' => $zoneId,
        ]);

        if ($zoneId !== null && (int) $record->zone_id !== $zoneId) {
            WorkpointRecord::withoutGlobalScopes()
                ->whereKey($record->getKey())
                ->update(['zone_id' => $zoneId]);
            $record = WorkpointRecord::withoutGlobalScopes()->find($record->getKey()) ?? $record;
        }

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
     * Get top users by total points in a period. Uses workpoint_period_totals when
     * use_period_totals_table is true (scalable); otherwise aggregates workpoint_records.
     *
     * @return Collection<int, array{user_id: int, total_points: int, name: string|null}>
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
            ->select('user_id', 'total_points')
            ->where('period_type', $period)
            ->where('period_key', $periodKey)
            ->orderByDesc('total_points')
            ->limit($limit)
            ->get();

        $items = $rows->map(fn ($row) => [
            'user_id' => (int) $row->user_id,
            'total_points' => (int) $row->total_points,
        ]);

        return $this->attachUserDisplayNames($items);
    }

    private function getTopInPeriodFromRecords(string $period, int $limit): Collection
    {
        $range = PeriodHelper::range($period);
        $table = WorkpointRecord::getTableName();

        $rows = WorkpointRecord::query()
            ->whereBetween($table . '.created_at', [$range['start'], $range['end']])
            ->selectRaw('user_id, SUM(points_delta) as total_points')
            ->groupBy('user_id')
            ->orderByDesc('total_points')
            ->limit($limit)
            ->get();

        $items = $rows->map(fn ($row) => [
            'user_id' => (int) $row->user_id,
            'total_points' => (int) $row->total_points,
        ]);

        return $this->attachUserDisplayNames($items);
    }

    /**
     * Adds {@see User} display name; list payloads are keyed by user_id.
     *
     * @param  Collection<int, array<string, mixed>>  $items  Must include user_id; may include total_points, last_at, etc.
     * @return Collection<int, array<string, mixed>>
     */
    private function attachUserDisplayNames(Collection $items): Collection
    {
        $ids = $items->pluck('user_id')->unique()->values()->all();
        if ($ids === []) {
            return $items;
        }

        $namesByKey = [];
        try {
            $user = new User;
            $keyName = $user->getKeyName();
            foreach (User::query()->whereIn($keyName, $ids)->get([$keyName, 'name']) as $model) {
                $namesByKey[(string) $model->getKey()] = $model->name;
            }
        } catch (\Throwable) {
            return $items;
        }

        return $items->map(function (array $item) use ($namesByKey) {
            $key = (string) $item['user_id'];
            $item['name'] = $namesByKey[$key] ?? null;

            return $item;
        });
    }

    /**
     * Get case config for an action key, merging default from config with zone override from DB.
     *
     * @return array{points: int, check: string, period?: string, cap?: int, limit_period?: string, limit_period_time?: int, descriptions: array<string, string>}|null
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
        $override = WorkpointZoneCase::withoutGlobalScopes()
            ->where('zone_id', $zoneId)
            ->where('case_key', $actionKey)
            ->first();
        if ($override === null) {
            return $default;
        }
        $merged = $default;
        foreach (['points', 'check', 'period', 'cap', 'limit_period', 'limit_period_time'] as $key) {
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
     * Optional hard cap from case config: max recordings per user per action in `limit_period`
     * (and per zone when $zoneId is set). Runs after the rule check; unset or zero `limit_period_time` means no limit.
     */
    public function isWithinLimitPeriod(int $userId, string $actionKey, ?int $zoneId, array $caseConfig): bool
    {
        $limitTime = (int) ($caseConfig['limit_period_time'] ?? 0);
        if ($limitTime <= 0) {
            return true;
        }

        $limitPeriod = (string) ($caseConfig['limit_period'] ?? PeriodHelper::PERIOD_DAY);
        $start = PeriodHelper::start($limitPeriod);

        if ($zoneId !== null) {
            $count = WorkpointRecord::withoutGlobalScopes()
                ->where('zone_id', $zoneId)
                ->where('user_id', $userId)
                ->where('action_key', $actionKey)
                ->where('created_at', '>=', $start)
                ->count();
        } else {
            $count = WorkpointRecord::query()
                ->where('user_id', $userId)
                ->where('action_key', $actionKey)
                ->where('created_at', '>=', $start)
                ->count();
        }

        return $count < $limitTime;
    }

    /**
     * Merged rules for the current zone (default config + DB overrides), formatted for the rules API.
     *
     * @return array<int, array{key: string, points: int, check: string, period: string|null, cap: int|null, limit_period: string|null, limit_period_time: int|null, description: string}>
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
                foreach (['points', 'check', 'period', 'cap', 'limit_period', 'limit_period_time'] as $k) {
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
                'limit_period' => isset($merged['limit_period']) ? (string) $merged['limit_period'] : null,
                'limit_period_time' => isset($merged['limit_period_time']) ? (int) $merged['limit_period_time'] : null,
                'description' => $description,
            ];
        }

        return $list;
    }

    /**
     * Save or update one zone case override. Case key must exist in workpoint_cases config.
     * Zone is enforced by BaseModel global scope (request context); caller must authorize (e.g. canManageZoneOrServer).
     *
     * @param  array{points: int, check: string, period?: string|null, cap?: int|null, limit_period?: string|null, limit_period_time?: int|null, descriptions?: array<string, string>|null}  $data
     * @throws \InvalidArgumentException If case_key is not in config.
     */
    public function saveZoneCase(string $caseKey, array $data): void
    {
        $defaultCases = $this->config->get('workpoint_cases', []);
        if (!isset($defaultCases[$caseKey]) || !is_array($defaultCases[$caseKey])) {
            throw new \InvalidArgumentException('Invalid case_key');
        }

        $limitPeriodTime = null;
        if (isset($data['limit_period_time']) && $data['limit_period_time'] !== '' && (int) $data['limit_period_time'] > 0) {
            $limitPeriodTime = (int) $data['limit_period_time'];
        }

        $payload = array_filter([
            'points' => (int) ($data['points'] ?? 0),
            'check' => is_string($data['check'] ?? null) ? $data['check'] : 'none',
            'period' => isset($data['period']) && $data['period'] !== '' ? (string) $data['period'] : null,
            'cap' => isset($data['cap']) && $data['cap'] !== '' ? (int) $data['cap'] : null,
            'limit_period' => isset($data['limit_period']) && $data['limit_period'] !== '' ? (string) $data['limit_period'] : null,
            'limit_period_time' => $limitPeriodTime,
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
                'limit_period' => isset($case['limit_period']) ? (string) $case['limit_period'] : null,
                'limit_period_time' => isset($case['limit_period_time']) ? (int) $case['limit_period_time'] : null,
                'descriptions' => $case['descriptions'] ?? null,
            ]);
        }
    }

    public function resolveRule(string $checkName): ?CheckRuleInterface
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
     * Paginated workpoint rows for a user in the current zone and time window (period).
     * Returns only fields required by frontend history UI.
     *
     * @param  array<string, string>  $caseNameByKey
     * @return array{items: array<int, array{id: int, case_key: string, case_name: string, points_delta: int, created_at: string|null}>, next_cursor: string|null}
     */
    public function getHistoryForUser(
        int $userId,
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
            ->where($table . '.user_id', $userId)
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
     * Leaderboard position (1-based) for one user in the given period within current zone.
     */
    public function getRankForUserInPeriod(int $userId, string $period): ?int
    {
        if (!PeriodHelper::isValidPeriod($period)) {
            return null;
        }

        $myTotal = $this->getUserTotalInPeriod($userId, $period);

        $range = PeriodHelper::range($period);
        $table = WorkpointRecord::getTableName();

        $betterCount = WorkpointRecord::query()
            ->whereBetween($table . '.created_at', [$range['start'], $range['end']])
            ->selectRaw($table . '.user_id, SUM(' . $table . '.points_delta) as total_points')
            ->groupBy($table . '.user_id')
            ->havingRaw('SUM(' . $table . '.points_delta) > ?', [$myTotal])
            ->count();

        return $betterCount + 1;
    }

    /**
     * Total points for user in period (current zone).
     */
    public function getUserTotalInPeriod(int $userId, string $period): int
    {
        if (!PeriodHelper::isValidPeriod($period)) {
            return 0;
        }
        $range = PeriodHelper::range($period);
        $table = WorkpointRecord::getTableName();

        return (int) WorkpointRecord::query()
            ->where($table . '.user_id', $userId)
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

        $fromCheck = null;
        if ($check === 'count_cap_per_period') {
            if ($cap !== null && $cap > 0 && $points > 0) {
                $fromCheck = $points * $cap;
            }
        } elseif (in_array($check, ['first_time', 'first_time_per_period', 'first_time_per_target'], true)) {
            $fromCheck = $points > 0 ? $points : null;
        }

        $limitT = isset($rule['limit_period_time']) ? (int) $rule['limit_period_time'] : 0;
        $fromLimit = ($limitT > 0 && $points > 0) ? $points * $limitT : null;

        if ($fromCheck === null && $fromLimit === null) {
            return null;
        }
        if ($fromCheck === null) {
            return $fromLimit;
        }
        if ($fromLimit === null) {
            return $fromCheck;
        }

        return min($fromCheck, $fromLimit);
    }

    /**
     * Points earned today per action_key, merged with zone rules (description, cap).
     *
     * @return array<int, array{key: string, description: string, earned: int, max_points: int|null}>
     */
    public function getTodayProgressByRules(int $userId, string $lang): array
    {
        $range = PeriodHelper::range(PeriodHelper::PERIOD_DAY);
        $table = WorkpointRecord::getTableName();

        $earned = WorkpointRecord::query()
            ->where($table . '.user_id', $userId)
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
                'key' => $key,
                'description' => $rule['description'],
                'earned' => (int) ($earned[$key] ?? 0),
                'max_points' => $this->maxPointsForRuleDisplay($rule),
            ];
        }

        return $out;
    }

    /**
     * Distinct users in zone with cursor pagination on (last_at, user_id).
     *
     * @return array{items: array<int, array{user_id: int, last_at: string|null, name: string|null}>, next_cursor: string|null}
     */
    public function listMembersInZoneCursor(?string $cursorEncoded, ?int $perPage = null): array
    {
        $perPage = $perPage ?? (int) $this->config->get('workpoint.admin_members_per_page', 30);
        $perPage = min(max($perPage, 1), 100);

        /** @var array{last_at: string, user_id: int}|null $cursor */
        $cursor = null;
        if ($cursorEncoded !== null && $cursorEncoded !== '') {
            $decodedRaw = base64_decode($cursorEncoded, true);
            if ($decodedRaw !== false) {
                $decoded = json_decode($decodedRaw, true);
                if (is_array($decoded) && isset($decoded['last_at']) && is_string($decoded['last_at'])) {
                    $uid = null;
                    if (isset($decoded['user_id']) && is_numeric($decoded['user_id'])) {
                        $uid = (int) $decoded['user_id'];
                    }
                    if ($uid !== null) {
                        $cursor = [
                            'last_at' => $decoded['last_at'],
                            'user_id' => $uid,
                        ];
                    }
                }
            }
        }

        // Inner subquery: latest record time per user (zone scope from BaseModel).
        $latestByUser = WorkpointRecord::query()
            ->selectRaw('user_id, MAX(created_at) as last_at')
            ->groupBy('user_id')
            ->toBase();

        // Outer query must not use model scopes because FROM is an alias (`user_last`).
        $rowsQuery = DB::query()
            ->fromSub($latestByUser, 'user_last')
            ->select(['user_id', 'last_at']);

        if ($cursor !== null) {
            $rowsQuery->where(function ($q) use ($cursor) {
                $q->where('last_at', '<', $cursor['last_at'])
                    ->orWhere(function ($q2) use ($cursor) {
                        $q2->where('last_at', '=', $cursor['last_at'])
                            ->where('user_id', '<', $cursor['user_id']);
                    });
            });
        }

        $rows = $rowsQuery
            ->orderByDesc('last_at')
            ->orderByDesc('user_id')
            ->limit($perPage + 1)
            ->get();

        $hasMore = $rows->count() > $perPage;
        $slice = $hasMore ? $rows->take($perPage)->values() : $rows->values();

        $items = [];
        foreach ($slice as $row) {
            $items[] = [
                'user_id' => (int) $row->user_id,
                'last_at' => $row->last_at,
            ];
        }

        $coll = $this->attachUserDisplayNames(collect($items));

        $nextCursor = null;
        if ($hasMore && $slice->isNotEmpty()) {
            $last = $slice->last();
            $payload = [
                'last_at' => $last->last_at,
                'user_id' => (int) $last->user_id,
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
        int $userId,
        string $historyPeriod,
        ?int $historyCursorId,
        string $lang
    ): array {
        $summary = $this->buildUserHistorySummary($userId, $lang);
        $history = $this->buildUserHistoryLogs($userId, $historyPeriod, $historyCursorId, $lang);
        return array_merge($summary, $history);
    }

    /**
     * Summary for history screen (no logs): totals + ranks + today progress.
     *
     * @return array{totals: array<string, int>, ranks: array<string, int|null>, today_by_rule: array<int, array{key: string, description: string, earned: int, max_points: int|null}>}
     */
    public function buildUserHistorySummary(
        int $userId,
        string $lang
    ): array {
        return [
            'totals' => [
                'day' => $this->getUserTotalInPeriod($userId, PeriodHelper::PERIOD_DAY),
                'week' => $this->getUserTotalInPeriod($userId, PeriodHelper::PERIOD_WEEK),
                'month' => $this->getUserTotalInPeriod($userId, PeriodHelper::PERIOD_MONTH),
                'year' => $this->getUserTotalInPeriod($userId, PeriodHelper::PERIOD_YEAR),
            ],
            'ranks' => [
                'day' => $this->getRankForUserInPeriod($userId, PeriodHelper::PERIOD_DAY),
                'week' => $this->getRankForUserInPeriod($userId, PeriodHelper::PERIOD_WEEK),
                'month' => $this->getRankForUserInPeriod($userId, PeriodHelper::PERIOD_MONTH),
                'year' => $this->getRankForUserInPeriod($userId, PeriodHelper::PERIOD_YEAR),
            ],
            'today_by_rule' => $this->getTodayProgressByRules($userId, $lang),
        ];
    }

    /**
     * Paginated history logs for history screen only.
     *
     * @return array{period: string, items: array<int, array{id: int, case_key: string, case_name: string, points_delta: int, created_at: string|null}>, next_cursor: string|null}
     */
    public function buildUserHistoryLogs(
        int $userId,
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
        $history = $this->getHistoryForUser($userId, $historyPeriod, $historyCursorId, $caseNameByKey);

        return array_merge(['period' => $historyPeriod], $history);
    }
}
