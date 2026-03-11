<?php declare(strict_types=1);

return [
    'task_accepted_sla' => [
        'points' => 1,
        'check' => 'first_time_per_target',
    ],
    'task_completed_on_time' => [
        'points' => 2,
        'check' => 'first_time_per_target',
    ],
    'app_first_visit_day' => [
        'points' => 1,
        'check' => 'first_time_per_period',
        'period' => 'day',
    ],
    'discipline_hour_worked' => [
        'points' => 1,
        'check' => 'count_cap_per_period',
        'period' => 'day',
        'cap' => 8,
    ],
];
