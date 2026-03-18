<?php declare(strict_types=1);

namespace Kennofizet\Workpoint\Controllers;

use Kennofizet\Workpoint\Support\PeriodHelper;
use Kennofizet\Workpoint\WorkpointRecordService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkpointController extends Controller
{
    private const TOP_LIMIT_MIN = 1;
    private const TOP_LIMIT_MAX = 100;

    private const LANG_CODES = ['vi', 'en'];

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

    /**
     * List workpoint cases (rules) for the rule page. Pass language (vi|en) for description locale.
     * Zone comes from X-Knf-Zone-Id (current zone). Returns merged rules (default + zone overrides) and isManager.
     */
    public function rules(Request $request): JsonResponse
    {
        $lang = $request->input('language', 'vi');
        if (!in_array($lang, self::LANG_CODES, true)) {
            $lang = 'vi';
        }
        $zoneId = $request->attributes->get('knf_core_user_zone_id_current');

        $list = $this->workpointService->getMergedRulesForZone($zoneId, $lang);

        return $this->apiResponseWithContext([
            'language' => $lang,
            'rules' => $list,
            'isManager' => self::isManager(),
        ]);
    }

    /**
     * Save or update one zone case override (manager only). Body: zone_id, case_key, points, check, period?, cap?, descriptions?.
     */
    public function saveRule(Request $request): JsonResponse
    {
        $zoneId = $request->input('zone_id');
        if ($zoneId === null || $zoneId === '') {
            return $this->apiErrorResponse('zone_id is required', 422);
        }
        $zoneId = (int) $zoneId;
        if (!self::canManageZoneOrServer($zoneId)) {
            return $this->apiErrorResponse('You do not have permission to manage this zone', 403);
        }

        $caseKey = $request->input('case_key');
        if (!is_string($caseKey) || $caseKey === '') {
            return $this->apiErrorResponse('case_key is required', 422);
        }

        try {
            $this->workpointService->saveZoneCase($zoneId, $caseKey, [
                'points' => $request->input('points', 0),
                'check' => $request->input('check', 'none'),
                'period' => $request->input('period'),
                'cap' => $request->input('cap'),
                'descriptions' => $request->input('descriptions'),
            ]);
        } catch (\InvalidArgumentException) {
            return $this->apiErrorResponse('Invalid case_key', 422);
        }

        return $this->apiResponseWithContext(['saved' => true]);
    }

    /**
     * Reset zone rules to default: remove all custom config for the zone and clone from config (manager only).
     */
    public function resetZoneRules(Request $request): JsonResponse
    {
        $zoneId = $request->input('zone_id');
        if ($zoneId === null || $zoneId === '') {
            return $this->apiErrorResponse('zone_id is required', 422);
        }
        $zoneId = (int) $zoneId;
        if (!self::canManageZoneOrServer($zoneId)) {
            return $this->apiErrorResponse('You do not have permission to manage this zone', 403);
        }

        $this->workpointService->resetZoneRulesToDefault($zoneId);

        return $this->apiResponseWithContext(['reset' => true]);
    }
}
