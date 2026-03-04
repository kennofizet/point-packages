<?php declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Company\Workpoint\Controllers\WorkpointController;
use Kennofizet\PackagesCore\Middleware\ValidateRewardPlayToken;

$prefix = config('packages-core.api_prefix', 'api/rewardplay');
$workpointPrefix = config('workpoint.api_prefix', 'workpoint');
$rateLimit = config('packages-core.rate_limit', 60);

Route::prefix($prefix . '/' . $workpointPrefix)
    ->middleware([
        'api',
        "throttle:{$rateLimit},1",
        ValidateRewardPlayToken::class,
    ])
    ->group(function () {
        Route::get('top', [WorkpointController::class, 'top']);
    });
