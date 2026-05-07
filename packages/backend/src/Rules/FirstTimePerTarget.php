<?php declare(strict_types=1);

namespace Kennofizet\Workpoint\Rules;

use Kennofizet\PackagesCore\Core\Model\BaseModelActions;
use Kennofizet\Workpoint\Contracts\CheckRuleInterface;
use Kennofizet\Workpoint\Models\WorkpointRecord;

class FirstTimePerTarget implements CheckRuleInterface
{
    /** @see FirstTime */
    public function allowed(
        object $user,
        ?object $target,
        string $actionKey,
        array $payload,
        array $caseConfig,
        ?int $zoneId = null
    ): bool {
        if ($target === null) {
            return false;
        }
        $userId = $caseConfig['user_id'] ?? null;
        if (!is_int($userId) || $userId <= 0) {
            return false;
        }

        $query = WorkpointRecord::query()
            ->where('user_id', $userId)
            ->where('action_key', $actionKey)
            ->where('target_type', $target::class)
            ->where('target_id', $target->getKey());

        if ($zoneId !== null) {
            $seasonId = BaseModelActions::currentUserSeasonId();
            $query = WorkpointRecord::withoutGlobalScopes()
                ->where('zone_id', $zoneId)
                ->when($seasonId !== null, static fn ($q) => $q->where('season_id', $seasonId))
                ->where('user_id', $userId)
                ->where('action_key', $actionKey)
                ->where('target_type', $target::class)
                ->where('target_id', $target->getKey());
        }

        return !$query->exists();
    }
}
