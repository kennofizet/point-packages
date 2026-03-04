<?php declare(strict_types=1);

namespace Company\Workpoint;

use Company\Workpoint\WorkpointRecordService;
use Illuminate\Support\ServiceProvider;

class WorkpointServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/Config/workpoint.php', 'workpoint');
        $this->mergeConfigFrom(__DIR__ . '/Config/workpoint_cases.php', 'workpoint_cases');

        $this->app->singleton(WorkpointRecordService::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/Config/workpoint.php' => config_path('workpoint.php'),
        ], 'workpoint-config');

        $this->publishes([
            __DIR__ . '/Config/workpoint_cases.php' => config_path('workpoint_cases.php'),
        ], 'workpoint-config');

        $this->publishes([
            __DIR__ . '/Migrations' => database_path('migrations'),
        ], 'workpoint-migrations');

        $coreBasePath = null;
        if (class_exists(\Kennofizet\PackagesCore\PackagesCoreServiceProvider::class)) {
            $coreBasePath = dirname(
                (new \ReflectionClass(\Kennofizet\PackagesCore\PackagesCoreServiceProvider::class))->getFileName(),
            );
        }
        if ($coreBasePath && is_dir($coreBasePath . '/Migrations')) {
            $this->publishes([
                $coreBasePath . '/Migrations' => database_path('migrations'),
            ], 'workpoint-migrations');
        }
        if ($coreBasePath && is_dir($coreBasePath . '/Config')) {
            $this->publishes([
                $coreBasePath . '/Config/packages-core.php' => config_path('packages-core.php'),
            ], 'workpoint-config');
        }

        $this->loadRoutesFrom(__DIR__ . '/Routes/api.php');
    }
}
