<?php declare(strict_types=1);

namespace Kennofizet\Workpoint\Rules;

use Kennofizet\PackagesCore\Core\Model\BaseModelActions;
use Kennofizet\Workpoint\Contracts\CheckRuleInterface;
use Kennofizet\Workpoint\Models\WorkpointRecord;
use Kennofizet\Workpoint\Support\PeriodHelper;

class FirstTimePerPeriod implements CheckRuleInterface
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
        $period = $caseConfig['period'] ?? PeriodHelper::PERIOD_DAY;
        $start = PeriodHelper::start($period);
        $userId = $caseConfig['user_id'] ?? null;
        if (!is_int($userId) || $userId <= 0) {
            return false;
        }

        $query = WorkpointRecord::query()
            ->where('user_id', $userId)
            ->where('action_key', $actionKey)
            ->where('created_at', '>=', $start);

        if ($zoneId !== null) {
            $seasonId = BaseModelActions::currentUserSeasonId();
            $query = WorkpointRecord::withoutGlobalScopes()
                ->where('zone_id', $zoneId)
                ->when($seasonId !== null, static fn ($q) => $q->where('season_id', $seasonId))
                ->where('user_id', $userId)
                ->where('action_key', $actionKey)
                ->where('created_at', '>=', $start);
        }

        return !$query->exists();
    }
}
