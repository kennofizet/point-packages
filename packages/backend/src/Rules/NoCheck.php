<?php declare(strict_types=1);

namespace Kennofizet\Workpoint\Rules;

use Kennofizet\Workpoint\Contracts\CheckRuleInterface;

class NoCheck implements CheckRuleInterface
{
    public function allowed(
        object $user,
        ?object $target,
        string $actionKey,
        array $payload,
        array $caseConfig,
        ?int $zoneId = null
    ): bool {
        return true;
    }
}
