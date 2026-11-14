<?php

namespace App\Shared\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\AbstractPaginator;

class ApiResponse
{
    public static function success(mixed $data = null, array $meta = [], int $status = 200): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => array_merge(self::baseMeta(), $meta),
        ], $status);
    }

    public static function fromResource(JsonResource|ResourceCollection $resource, array $meta = [], int $status = 200): JsonResponse
    {
        $payload = $resource->response()->getData(true);

        $data = $payload['data'] ?? $payload;
        $paginationMeta = [];

        if (isset($payload['links'], $payload['meta'])) {
            $paginationMeta = [
                'pagination' => [
                    'links' => $payload['links'],
                    'meta' => $payload['meta'],
                ],
            ];
        }

        return self::success($data, array_merge($paginationMeta, $meta), $status);
    }

    public static function paginated(AbstractPaginator $paginator, callable $mapper, array $meta = []): JsonResponse
    {
        return self::success(
            collect($paginator->items())->map($mapper)->values()->all(),
            array_merge([
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
            ], $meta)
        );
    }

    public static function error(string $code, string $message, int $status = 400, array $details = [], array $meta = []): JsonResponse
    {
        $error = [
            'code' => $code,
            'message' => $message,
        ];

        if ($details !== []) {
            $error['details'] = $details;
        }

        return response()->json([
            'error' => $error,
            'meta' => array_merge(self::baseMeta(), $meta),
        ], $status);
    }

    private static function baseMeta(): array
    {
        return [
            'request_id' => request()->attributes->get('request_id')
                ?? request()->headers->get('X-Request-Id')
                ?? (string) str()->uuid(),
            'schema_version' => (string) config('codina.schema_version', '1.0'),
        ];
    }
}
