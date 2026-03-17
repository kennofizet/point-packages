<?php declare(strict_types=1);

return [
    'task_accepted_sla' => [
        'points' => 1,
        'check' => 'first_time_per_target',
        'descriptions' => [
            'vi' => 'Chấp nhận task đúng SLA',
            'en' => 'Accept task within SLA',
        ],
    ],
    'task_completed_on_time' => [
        'points' => 2,
        'check' => 'first_time_per_target',
        'descriptions' => [
            'vi' => 'Hoàn thành task đúng hạn',
            'en' => 'Complete task on time',
        ],
    ],
    'app_first_visit_day' => [
        'points' => 1,
        'check' => 'first_time_per_period',
        'period' => 'day',
        'descriptions' => [
            'vi' => 'Truy cập app lần đầu trong ngày',
            'en' => 'First app visit of the day',
        ],
    ],
    'discipline_hour_worked' => [
        'points' => 1,
        'check' => 'count_cap_per_period',
        'period' => 'day',
        'cap' => 8,
        'descriptions' => [
            'vi' => 'Làm việc có kỷ luật (tối đa 8 lần/ngày)',
            'en' => 'Discipline hour worked (max 8 per day)',
        ],
    ],
];
