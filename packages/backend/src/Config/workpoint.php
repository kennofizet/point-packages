<?php declare(strict_types=1);

return [
    'table' => env('WORKPOINT_TABLE', 'workpoint_records'),
    'period_totals_table' => env('WORKPOINT_PERIOD_TOTALS_TABLE', 'workpoint_period_totals'),
    'zone_cases_table' => env('WORKPOINT_ZONE_CASES_TABLE', 'workpoint_zone_cases'),
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
    | Subject name column for "top by period" response
    |--------------------------------------------------------------------------
    | When set (e.g. 'name', 'username'), getTopInPeriod will resolve the subject
    | by relation and add subject_name to each item. Leave null to omit names.
    */
    'subject_name_col' => env('WORKPOINT_SUBJECT_NAME_COL', null),

    /*
    |--------------------------------------------------------------------------
    | Subject model class (for history / admin APIs)
    |--------------------------------------------------------------------------
    | Must match the model used with HasWorkpointRecords (polymorphic subject_type).
    */
    'subject_class' => env('WORKPOINT_SUBJECT_CLASS', 'App\\Models\\User'),

    /** Default page size for history lists (cursor pagination). */
    'history_per_page' => (int) env('WORKPOINT_HISTORY_PER_PAGE', 30),

    /** Default page size for manager subject list (cursor pagination). */
    'admin_subjects_per_page' => (int) env('WORKPOINT_ADMIN_SUBJECTS_PER_PAGE', 30),

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
