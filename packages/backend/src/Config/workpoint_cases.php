<?php declare(strict_types=1);

return [
    'task.accepted_sla' => [
        'points' => 1,
        'check' => 'first_time_per_target',
    ],
    'task.completed_on_time' => [
        'points' => 2,
        'check' => 'first_time_per_target',
    ],
    'app.first_visit_day' => [
        'points' => 1,
        'check' => 'first_time_per_period',
        'period' => 'day',
    ],
    'discipline.hour_worked' => [
        'points' => 1,
        'check' => 'count_cap_per_period',
        'period' => 'day',
        'cap' => 8,
    ],
];
