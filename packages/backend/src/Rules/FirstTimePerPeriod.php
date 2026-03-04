<?php declare(strict_types=1);

namespace Company\Workpoint\Rules;

use Company\Workpoint\Contracts\CheckRuleInterface;
use Company\Workpoint\Models\WorkpointRecord;
use Company\Workpoint\Support\PeriodHelper;

class FirstTimePerPeriod implements CheckRuleInterface
{
    public function allowed(
        object $subject,
        ?object $target,
        string $actionKey,
        array $payload,
        array $caseConfig
    ): bool {
        $period = $caseConfig['period'] ?? PeriodHelper::PERIOD_DAY;
        $start = PeriodHelper::start($period);

        return !WorkpointRecord::query()
            ->where('subject_type', $subject::class)
            ->where('subject_id', $subject->getKey())
            ->where('action_key', $actionKey)
            ->where('created_at', '>=', $start)
            ->exists();
    }
}
