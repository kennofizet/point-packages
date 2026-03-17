<?php declare(strict_types=1);

namespace Kennofizet\Workpoint\Rules;

use Kennofizet\Workpoint\Contracts\CheckRuleInterface;
use Kennofizet\Workpoint\Models\WorkpointRecord;
use Kennofizet\Workpoint\Support\PeriodHelper;

class FirstTimePerPeriod implements CheckRuleInterface
{
    public function allowed(
        object $subject,
        ?object $target,
        string $actionKey,
        array $payload,
        array $caseConfig,
        ?int $zoneId = null
    ): bool {
        $period = $caseConfig['period'] ?? PeriodHelper::PERIOD_DAY;
        $start = PeriodHelper::start($period);

        $query = WorkpointRecord::query()
            ->where('subject_type', $subject::class)
            ->where('subject_id', $subject->getKey())
            ->where('action_key', $actionKey)
            ->where('created_at', '>=', $start);

        if ($zoneId !== null) {
            $query->where('zone_id', $zoneId);
        }

        return !$query->exists();
    }
}
