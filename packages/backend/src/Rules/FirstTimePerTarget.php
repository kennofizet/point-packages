<?php declare(strict_types=1);

namespace Kennofizet\Workpoint\Rules;

use Kennofizet\Workpoint\Contracts\CheckRuleInterface;
use Kennofizet\Workpoint\Models\WorkpointRecord;

class FirstTimePerTarget implements CheckRuleInterface
{
    public function allowed(
        object $subject,
        ?object $target,
        string $actionKey,
        array $payload,
        array $caseConfig,
        ?int $zoneId = null
    ): bool {
        if ($target === null) {
            return false;
        }

        $query = WorkpointRecord::query()
            ->where('subject_type', $subject::class)
            ->where('subject_id', $subject->getKey())
            ->where('action_key', $actionKey)
            ->where('target_type', $target::class)
            ->where('target_id', $target->getKey());

        if ($zoneId !== null) {
            $query->where('zone_id', $zoneId);
        }

        return !$query->exists();
    }
}
