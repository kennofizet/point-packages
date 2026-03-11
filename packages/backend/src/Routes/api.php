<?php declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Kennofizet\Workpoint\Controllers\WorkpointController;

$prefix = config('packages-core.api_prefix', 'api/knf');
$workpointPrefix = config('workpoint.api_prefix', 'workpoint');
$rateLimit = config('packages-core.rate_limit', 60);

Route::prefix($prefix . '/' . $workpointPrefix)
    ->middleware([
        'api',
        "throttle:{$rateLimit},1",
        'knf.core.token',
    ])
    ->group(function () {
        Route::get('top', [WorkpointController::class, 'top']);
    });
