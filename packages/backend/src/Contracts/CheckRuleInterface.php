<?php declare(strict_types=1);

namespace Kennofizet\Workpoint\Contracts;

interface CheckRuleInterface
{
    /**
     * Determine if recording is allowed for this subject/target/action.
     *
     * @param  object  $subject  The model that receives points (e.g. User).
     * @param  object|null  $target  Optional target (e.g. Task, Project).
     * @param  string  $actionKey  Action key from workpoint_cases.
     * @param  array  $payload  Extra data passed when recording.
     * @param  array  $caseConfig  Config for this action (points, check, period, cap, etc.).
     * @return bool  True if the record is allowed, false otherwise.
     */
    public function allowed(
        object $subject,
        ?object $target,
        string $actionKey,
        array $payload,
        array $caseConfig,
    ): bool;
}
