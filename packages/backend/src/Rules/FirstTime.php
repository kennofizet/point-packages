<?php declare(strict_types=1);

namespace Kennofizet\Workpoint\Rules;

use Kennofizet\Workpoint\Contracts\CheckRuleInterface;
use Kennofizet\Workpoint\Models\WorkpointRecord;

class FirstTime implements CheckRuleInterface
{
    public function allowed(
        object $subject,
        ?object $target,
        string $actionKey,
        array $payload,
        array $caseConfig
    ): bool {
        return !WorkpointRecord::query()
            ->where('subject_type', $subject::class)
            ->where('subject_id', $subject->getKey())
            ->where('action_key', $actionKey)
            ->exists();
    }
}
