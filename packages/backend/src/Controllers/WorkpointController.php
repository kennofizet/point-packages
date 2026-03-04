<?php declare(strict_types=1);

namespace Company\Workpoint\Controllers;

use Company\Workpoint\Support\PeriodHelper;
use Company\Workpoint\WorkpointRecordService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkpointController extends Controller
{
    private const TOP_LIMIT_MIN = 1;
    private const TOP_LIMIT_MAX = 100;

    public function __construct(
        private readonly WorkpointRecordService $workpointService
    ) {
    }

    /**
     * Top users by total workpoints in the given period (day|week|month|year). Scoped by zone (packages-core).
     */
    public function top(Request $request): JsonResponse
    {
        $period = $request->input('period', PeriodHelper::PERIOD_WEEK);
        if (!PeriodHelper::isValidPeriod($period)) {
            $period = PeriodHelper::PERIOD_WEEK;
        }
        $limit = (int) $request->input('limit', 10);
        $limit = min(max($limit, self::TOP_LIMIT_MIN), self::TOP_LIMIT_MAX);

        $items = $this->workpointService->getTopInPeriod($period, $limit);

        return $this->apiResponseWithContext([
            'period' => $period,
            'items' => $items->values()->all(),
        ]);
    }
}
