<?php declare(strict_types=1);

return [
    'table' => env('WORKPOINT_TABLE', 'workpoint_records'),
    'period_totals_table' => env('WORKPOINT_PERIOD_TOTALS_TABLE', 'workpoint_period_totals'),
    'api_prefix' => env('WORKPOINT_API_PREFIX', 'workpoint'),

    /*
    |--------------------------------------------------------------------------
    | Use period totals table for "top by period" (scalable for large data)
    |--------------------------------------------------------------------------
    | When true, getTopInPeriod reads from workpoint_period_totals (synced on each record).
    | When false, top is computed with SUM/groupBy on workpoint_records (fine for small data).
    */
    'use_period_totals_table' => env('WORKPOINT_USE_PERIOD_TOTALS_TABLE', true),

    /*
    |--------------------------------------------------------------------------
    | Event fired when a workpoint is recorded
    |--------------------------------------------------------------------------
    | The main app (or Coin package) can listen to this event.
    */
    'event_class' => \Kennofizet\Workpoint\Events\WorkpointRecorded::class,

    /*
    |--------------------------------------------------------------------------
    | Listeners called after a workpoint is recorded (after the event is dispatched)
    |--------------------------------------------------------------------------
    | Each class must implement Kennofizet\Workpoint\Contracts\AfterWorkpointRecordedListener
    | and have a handle(WorkpointRecord $record): void method.
    | Example: ['App\Listeners\UpdateCoinOnWorkpoint', 'App\Listeners\NotifyUserWorkpointEarned']
    */
    'after_record_listeners' => [
        // \App\Listeners\UpdateCoinOnWorkpoint::class,
    ],

    'rules' => [
        'none' => \Kennofizet\Workpoint\Rules\NoCheck::class,
        'first_time' => \Kennofizet\Workpoint\Rules\FirstTime::class,
        'first_time_per_target' => \Kennofizet\Workpoint\Rules\FirstTimePerTarget::class,
        'first_time_per_period' => \Kennofizet\Workpoint\Rules\FirstTimePerPeriod::class,
        'count_cap_per_period' => \Kennofizet\Workpoint\Rules\CountCapPerPeriod::class,
    ],
];
