<?php declare(strict_types=1);

namespace Company\Workpoint\Controllers;

use Kennofizet\PackagesCore\Traits\GlobalDataTrait;
use Kennofizet\PackagesCore\Core\Model\BaseModelActions;
use Kennofizet\PackagesCore\Core\Model\BaseModelResponse;
use Illuminate\Http\JsonResponse;

abstract class Controller
{
    use GlobalDataTrait, BaseModelActions;

    public function apiResponseWithContext(array $data = [], int $status = 200): JsonResponse
    {
        return response()->json(BaseModelResponse::success('Success', $data), $status);
    }

    protected function handleException(\Exception $e, int $status = 500): JsonResponse
    {
        return $this->apiErrorResponse($e->getMessage(), $status);
    }
}
