<?php declare(strict_types=1);

namespace Kennofizet\Workpoint\Controllers;

use Kennofizet\PackagesCore\Services\SeasonService;
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

        $list = $this->workpointService->getMergedRulesForZone($lang);

        return $this->apiResponseWithContext([
            'language' => $lang,
            'rules' => $list,
            'isManager' => self::isManager(),
        ]);
    }

    /**
     * Save or update one zone case override (manager only). Zone from current context (e.g. X-Knf-Zone-Id).
     * Body: case_key, points, check, period?, cap?, limit_period?, limit_period_time?, descriptions?.
     */
    public function saveRule(Request $request): JsonResponse
    {
        if (!self::canManageZoneOrServer(self::currentUserZoneId())) {
            return $this->apiErrorResponse('You do not have permission to manage this zone', 403);
        }

        $caseKey = $request->input('case_key');
        if (!is_string($caseKey) || $caseKey === '') {
            return $this->apiErrorResponse('case_key is required', 422);
        }

        try {
            $this->workpointService->saveZoneCase($caseKey, [
                'points' => $request->input('points', 0),
                'check' => $request->input('check', 'none'),
                'period' => $request->input('period'),
                'cap' => $request->input('cap'),
                'limit_period' => $request->input('limit_period'),
                'limit_period_time' => $request->input('limit_period_time'),
                'descriptions' => $request->input('descriptions'),
            ]);
        } catch (\InvalidArgumentException) {
            return $this->apiErrorResponse('Invalid case_key', 422);
        }

        return $this->apiResponseWithContext(['saved' => true]);
    }

    /**
     * Reset zone rules to default for the current zone (manager only). Zone from context (e.g. X-Knf-Zone-Id).
     */
    public function resetZoneRules(Request $request): JsonResponse
    {
        if (!self::canManageZoneOrServer(self::currentUserZoneId())) {
            return $this->apiErrorResponse('You do not have permission to manage this zone', 403);
        }

        $this->workpointService->resetZoneRulesToDefault();

        return $this->apiResponseWithContext(['reset' => true]);
    }

    public function seasons(Request $request): JsonResponse
    {
        if (!class_exists(SeasonService::class)) {
            return $this->apiResponseWithContext([
                'seasons' => [],
            ]);
        }

        $service = app(SeasonService::class);
        $seasons = $this->workpointService->attachSeasonRates($service->listByCurrentZone());

        return $this->apiResponseWithContext([
            'seasons' => $seasons,
        ]);
    }

    public function createSeason(Request $request): JsonResponse
    {
        if (!class_exists(SeasonService::class)) {
            return $this->apiErrorResponse('Season service is unavailable', 501);
        }

        $name = trim((string) $request->input('name', ''));
        if ($name === '') {
            return $this->apiErrorResponse('name is required', 422);
        }
        $rateConvert = (float) $request->input('rate_convert', 1);
        if (!is_numeric($request->input('rate_convert', 1)) || $rateConvert <= 0) {
            return $this->apiErrorResponse('rate_convert must be a positive number', 422);
        }

        try {
            $season = app(SeasonService::class)->createForCurrentZone(
                $name,
                $request->input('starts_at'),
                $request->input('ends_at')
            );
            $this->workpointService->saveSeasonRateConvert((int) $season->id, $rateConvert);
        } catch (\Throwable $e) {
            return $this->apiErrorResponse($e->getMessage(), 422);
        }

        return $this->apiResponseWithContext([
            'season' => [
                'id' => (int) $season->id,
                'name' => (string) $season->name,
                'is_active' => (bool) $season->is_active,
                'starts_at' => $season->starts_at?->toIso8601String(),
                'ends_at' => $season->ends_at?->toIso8601String(),
                'rate_convert' => $rateConvert,
            ],
        ], 201);
    }

    public function activateSeason(Request $request, int $seasonId): JsonResponse
    {
        if (!class_exists(SeasonService::class)) {
            return $this->apiErrorResponse('Season service is unavailable', 501);
        }

        try {
            $season = app(SeasonService::class)->activateForCurrentZone($seasonId);
        } catch (\Throwable $e) {
            return $this->apiErrorResponse($e->getMessage(), 422);
        }

        return $this->apiResponseWithContext([
            'season' => [
                'id' => (int) $season->id,
                'name' => (string) $season->name,
                'is_active' => (bool) $season->is_active,
                'rate_convert' => $this->workpointService->getSeasonRateConvert((int) $season->id),
            ],
        ]);
    }

    private function parseLanguage(Request $request): string
    {
        $lang = $request->input('language', 'vi');
        if (!in_array($lang, self::LANG_CODES, true)) {
            $lang = 'vi';
        }
        return $lang;
    }

    private function authorizeHistoryAccess(): ?JsonResponse
    {
        if (!self::canManageZoneOrServer(self::currentUserZoneId())) {
            return $this->apiErrorResponse('Forbidden', 403);
        }

        return null;
    }

    /**
     * Current user's summary for history screen (totals, ranks, today_by_rule).
     */
    public function historyMeSummary(Request $request): JsonResponse
    {
        $lang = $this->parseLanguage($request);

        $userId = self::currentUserId();

        $data = $this->workpointService->buildUserHistorySummary(
            $userId,
            $lang
        );
        $data['isManager'] = self::isManager();

        return $this->apiResponseWithContext($data);
    }

    /**
     * Current user's logs for history screen with cursor pagination.
     */
    public function historyMeLogs(Request $request): JsonResponse
    {
        $period = $request->input('period', PeriodHelper::PERIOD_WEEK);
        if (!PeriodHelper::isValidPeriod($period)) {
            $period = PeriodHelper::PERIOD_WEEK;
        }
        $cursorRaw = $request->input('cursor');
        $cursorId = $cursorRaw !== null && $cursorRaw !== '' ? (int) $cursorRaw : null;

        $lang = $this->parseLanguage($request);

        $userId = self::currentUserId();
        $data = $this->workpointService->buildUserHistoryLogs(
            $userId,
            $period,
            $cursorId,
            $lang
        );

        return $this->apiResponseWithContext($data);
    }

    /**
     * Summary for one user (self or manager).
     */
    public function historyUserSummary(Request $request, int $userId): JsonResponse
    {
        $deny = $this->authorizeHistoryAccess();
        if ($deny !== null) {
            return $deny;
        }

        $lang = $this->parseLanguage($request);
        $data = $this->workpointService->buildUserHistorySummary(
            $userId,
            $lang
        );

        return $this->apiResponseWithContext($data);
    }

    /**
     * Paginated logs for one user (self or manager).
     */
    public function historyUserLogs(Request $request, int $userId): JsonResponse
    {
        $deny = $this->authorizeHistoryAccess();
        if ($deny !== null) {
            return $deny;
        }

        $period = $request->input('period', PeriodHelper::PERIOD_WEEK);
        if (!PeriodHelper::isValidPeriod($period)) {
            $period = PeriodHelper::PERIOD_WEEK;
        }
        $cursorRaw = $request->input('cursor');
        $cursorId = $cursorRaw !== null && $cursorRaw !== '' ? (int) $cursorRaw : null;
        $lang = $this->parseLanguage($request);

        $data = $this->workpointService->buildUserHistoryLogs(
            $userId,
            $period,
            $cursorId,
            $lang
        );

        return $this->apiResponseWithContext($data);
    }

    /**
     * Cursor-paginated list of users who have workpoints in the current zone (manager).
     */
    public function adminMembers(Request $request): JsonResponse
    {
        $cursor = $request->input('cursor');
        $cursor = is_string($cursor) && $cursor !== '' ? $cursor : null;

        $data = $this->workpointService->listMembersInZoneCursor($cursor);

        return $this->apiResponseWithContext($data);
    }
}
