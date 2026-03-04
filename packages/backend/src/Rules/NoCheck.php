<?php declare(strict_types=1);

namespace Company\Workpoint\Rules;

use Company\Workpoint\Contracts\CheckRuleInterface;

class NoCheck implements CheckRuleInterface
{
    public function allowed(
        object $subject,
        ?object $target,
        string $actionKey,
        array $payload,
        array $caseConfig
    ): bool {
        return true;
    }
}
