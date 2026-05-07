<?php declare(strict_types=1);

namespace Kennofizet\Workpoint\Rules;

use Kennofizet\PackagesCore\Core\Model\BaseModelActions;
use Kennofizet\Workpoint\Contracts\CheckRuleInterface;
use Kennofizet\Workpoint\Models\WorkpointRecord;

class FirstTime implements CheckRuleInterface
{
    public function allowed(
        object $user,
        ?object $target,
        string $actionKey,
        array $payload,
        array $caseConfig,
        ?int $zoneId = null
    ): bool {
        $userId = $caseConfig['user_id'] ?? null;
        if (!is_int($userId) || $userId <= 0) {
            return false;
        }

        $query = WorkpointRecord::query()
            ->where('user_id', $userId)
            ->where('action_key', $actionKey);

        if ($zoneId !== null) {
            $seasonId = BaseModelActions::currentUserSeasonId();
            $query = WorkpointRecord::withoutGlobalScopes()
                ->where('zone_id', $zoneId)
                ->when($seasonId !== null, static fn ($q) => $q->where('season_id', $seasonId))
                ->where('user_id', $userId)
                ->where('action_key', $actionKey);
        }

        return !$query->exists();
    }
}
