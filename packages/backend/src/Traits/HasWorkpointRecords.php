<?php declare(strict_types=1);

namespace Kennofizet\Workpoint\Traits;

use Kennofizet\PackagesCore\Core\Model\BaseModelActions;
use Kennofizet\PackagesCore\Models\User;
use Kennofizet\Workpoint\Models\WorkpointRecord;
use Kennofizet\Workpoint\WorkpointRecordService;

trait HasWorkpointRecords
{
    /**
     * Record a workpoint for this user.
     *
     * @param  object|null  $target  Optional target (e.g. Task, Project).
     * @param  array<int, int|null>|null  $zoneIds  Zone IDs to record for. Null (or containing null) means all zones of this user.
     * @return \Kennofizet\Workpoint\Models\WorkpointRecord|null  The created record or null if not allowed.
     */
    public function recordWorkpoint(
        string $actionKey,
        object|null $target = null,
        array $payload = [],
        ?array $zoneIds = null,
        ?int $userId = null
    ): ?WorkpointRecord
    {
        $service = app(WorkpointRecordService::class);
        $resolvedUserId = $this->resolveWorkpointUserId($userId);
        $resolvedZoneIds = $this->resolveWorkpointZoneIds($resolvedUserId, $zoneIds);

        return $service->record($this, $actionKey, $target, $payload, $resolvedZoneIds, $resolvedUserId);
    }

    /**
     * Whether this user already has a stored workpoint row for the action (and optional target) in the current zone.
     * Does not evaluate rule checks — only presence of a record. Use before calling {@see recordWorkpoint} or for UI gates.
     *
     * @param  object|null  $target  Same as {@see recordWorkpoint}: e.g. project/task model for per-target keys; omit for global keys.
     */
    public function hasWorkpointRecord(string $actionKey, object|null $target = null, ?array $zoneIds = null, ?int $userId = null): bool
    {
        $resolvedUserId = $this->resolveWorkpointUserId($userId);
        if ($resolvedUserId === null || $resolvedUserId <= 0) {
            return false;
        }

        $query = WorkpointRecord::withoutGlobalScopes()
            ->where('user_id', $resolvedUserId)
            ->where('action_key', $actionKey);

        if ($target === null) {
            $query->whereNull('target_type')->whereNull('target_id');
        } else {
            $query->where('target_type', $target::class)
                ->where('target_id', $target->getKey());
        }

        $resolvedZoneIds = $this->resolveWorkpointZoneIds($resolvedUserId, $zoneIds);
        if ($resolvedZoneIds === []) {
            return false;
        }
        if ($resolvedZoneIds !== []) {
            $query->whereIn('zone_id', $resolvedZoneIds);
        }

        return $query->exists();
    }

    public function canWorkpointRecordZoneIds(string $actionKey, object|null $target = null, ?array $zoneIds = null, ?int $userId = null): array
    {
        $allowedZoneIds = [];

        $resolvedUserId = $this->resolveWorkpointUserId($userId);
        if ($resolvedUserId === null || $resolvedUserId <= 0) {
            return $allowedZoneIds;
        }
        $resolvedZoneIds = $this->resolveWorkpointZoneIds($resolvedUserId, $zoneIds);
        if ($resolvedZoneIds === []) {
            return $allowedZoneIds;
        }
        $user = User::query()->find($resolvedUserId);
        if (!is_object($user)) {
            return $allowedZoneIds;
        }

        foreach ($resolvedZoneIds as $zoneId) {
            $service = app(WorkpointRecordService::class);
            $caseConfig = $service->getCaseConfig($actionKey, $zoneId);
            if ($caseConfig === null) {
                continue;
            }
            $caseConfig['user_id'] = $userId;

            $checkName = $caseConfig['check'] ?? 'none';
            $rule = $service->resolveRule($checkName);

            if ($rule === null || !$rule->allowed($user, $target, $actionKey, [], $caseConfig, $zoneId)) {
                continue;
            }
            $allowedZoneIds[] = $zoneId;
        }

        return $allowedZoneIds;
    }

    /**
     * Zone IDs the user is a member of (from core {@see User}: `zones` / `zone_id`).
     * Pass null to use the current authenticated user ({@see BaseModelActions::currentUserId}).
     *
     * @return array<int, int>
     */
    public function getZoneIdsForUser(?int $userId = null): array
    {
        $resolved = $this->resolveWorkpointUserId($userId);
        if ($resolved === null || $resolved <= 0) {
            return [];
        }

        return $this->extractCurrentUserZoneIds($resolved);
    }

    private function resolveWorkpointUserId(?int $userId = null): ?int
    {
        $id = BaseModelActions::currentUserId() ?? $userId;

        return $id === null ? null : (int) $id;
    }

    /**
     * @param  array<int, int|null>|null  $zoneIds
     * @return array<int, int|null>
     */
    private function resolveWorkpointZoneIds(?int $resolvedUserId, ?array $zoneIds): array
    {
        if ($resolvedUserId === null || $resolvedUserId <= 0) {
            return [];
        }

        $userZoneIds = $this->extractCurrentUserZoneIds($resolvedUserId);
        if ($zoneIds === null || in_array(null, $zoneIds, true)) {
            return array_map(static fn (int $id): ?int => $id, $userZoneIds);
        }

        $requested = [];
        foreach ($zoneIds as $zoneId) {
            if (is_int($zoneId) && $zoneId > 0) {
                $requested[$zoneId] = $zoneId;
            }
        }
        if ($requested === []) {
            return [];
        }
        if ($userZoneIds === []) {
            return [];
        }

        return array_values(array_map(
            static fn (int $id): ?int => $id,
            array_intersect(array_values($requested), $userZoneIds)
        ));
    }

    /**
     * Context zones from {@see BaseModelActions::currentUserZoneIds}, then user model zones when empty.
     *
     * @return array<int, int>
     */
    private function extractCurrentUserZoneIds(int $userId): array
    {
        if (method_exists(BaseModelActions::class, 'currentUserZoneIds')) {
            if(BaseModelActions::currentUserZoneIds() !== []) {
                return BaseModelActions::currentUserZoneIds();
            }
        }
        

        return $this->zoneIdsForUserMember($userId);
    }

    /**
     * @return array<int, int>
     */
    private function zoneIdsForUserMember(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $userModel = User::query()->find($userId);
        if (!is_object($userModel)) {
            return [];
        }

        $zoneIds = [];

        if (method_exists($userModel, 'zones')) {
            try {
                $zones = $userModel->zones;
                if ($zones instanceof \Illuminate\Support\Collection) {
                    $zoneIds = $zones->pluck('id')->filter()->map(static fn ($v): int => (int) $v)->all();
                }
            } catch (\Throwable) {
            }
        }

        if ($zoneIds === [] && method_exists($userModel, 'zones')) {
            try {
                $zoneIds = $userModel->zones()->pluck('id')->map(static fn ($v): int => (int) $v)->all();
            } catch (\Throwable) {
            }
        }

        return array_values(array_unique(array_filter($zoneIds, static fn (int $id): bool => $id > 0)));
    }
}
