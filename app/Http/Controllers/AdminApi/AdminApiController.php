<?php

namespace App\Http\Controllers\AdminApi;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shared base for the admin automation REST API. Responses follow the Pro CLI
 * envelope: {"code": 0, "data": ..., "message": ""} on success, non-zero code
 * with an HTTP error status on failure. List endpoints add "total".
 */
abstract class AdminApiController extends Controller
{
    /** Standard page size cap for list endpoints. */
    protected const MAX_PAGE_SIZE = 200;

    protected function ok(mixed $data = null, string $message = ''): JsonResponse
    {
        return response()->json([
            'code' => 0,
            'data' => $data,
            'message' => $message,
        ]);
    }

    protected function created(mixed $data = null, string $message = ''): JsonResponse
    {
        return response()->json([
            'code' => 0,
            'data' => $data,
            'message' => $message,
        ], 201);
    }

    protected function fail(string $message, int $status = 400, int $code = 1): JsonResponse
    {
        return response()->json([
            'code' => $code,
            'data' => null,
            'message' => $message,
        ], $status);
    }

    /** Envelope a paginator into {code,data,total,current,total_pages}. */
    protected function paginated(LengthAwarePaginator $page, callable $transform): JsonResponse
    {
        return response()->json([
            'code' => 0,
            'data' => collect($page->items())->map($transform)->values()->all(),
            'total' => $page->total(),
            'current' => $page->currentPage(),
            'total_pages' => $page->lastPage(),
            'message' => '',
        ]);
    }

    protected function perPage(Request $request): int
    {
        $n = (int) $request->query('page_size', $request->query('per_page', 50));

        return max(1, min($n, self::MAX_PAGE_SIZE));
    }

    /** The ApiToken behind this request (guaranteed present after auth:api-token). */
    protected function token(Request $request): ApiToken
    {
        return $request->attributes->get('api_token');
    }
}
