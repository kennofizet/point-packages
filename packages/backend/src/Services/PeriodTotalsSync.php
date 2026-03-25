<?php declare(strict_types=1);

namespace Kennofizet\Workpoint\Services;

use Kennofizet\Workpoint\Models\WorkpointRecord;
use Kennofizet\Workpoint\Models\WorkpointPeriodTotal;
use Kennofizet\Workpoint\Support\PeriodHelper;

/**
 * Syncs workpoint_period_totals when a record is created so "top by period" can read from the summary table.
 * Package-only; called from WorkpointRecordService after recording.
 */
class PeriodTotalsSync
{
    public function syncRecord(WorkpointRecord $record): void
    {
        $userId = $record->user_id;
        if ($userId === null) {
            return;
        }
        $entityType = $record->subject_type;
        $entityId = $record->subject_id;
        $delta = (int) $record->points_delta;
        if ($delta === 0) {
            return;
        }

        foreach (PeriodHelper::PERIODS_ALL as $periodType) {
            $periodKey = PeriodHelper::periodKey($periodType);
            // Match row by the record's zone (explicit + no global scope: safe in queue / multi-zone).
            $row = WorkpointPeriodTotal::withoutGlobalScopes()
                ->where('zone_id', $record->zone_id)
                ->where('user_id', $userId)
                ->where('period_type', $periodType)
                ->where('period_key', $periodKey)
                ->first();
            if ($row) {
                $row->increment('total_points', $delta);
            } else {
                WorkpointPeriodTotal::withoutGlobalScopes()->create([
                    'zone_id' => $record->zone_id,
                    'user_id' => $userId,
                    'subject_type' => $entityType,
                    'subject_id' => $entityId,
                    'period_type' => $periodType,
                    'period_key' => $periodKey,
                    'total_points' => $delta,
                ]);
            }
        }
    }
}
