<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $database = 'ok';

        try {
            DB::connection()->getPdo();
            DB::select('select 1 as ok');
        } catch (Throwable) {
            $database = 'error';
        }

        $status = $database === 'ok' ? 200 : 503;

        return ApiResponse::success([
            'service' => 'codina-intelligence-os',
            'phase' => '01',
            'status' => $database === 'ok' ? 'healthy' : 'degraded',
            'checks' => [
                'database' => $database,
                'external_http_allowed' => (bool) config('codina.allow_external_http'),
                'queue' => config('queue.default'),
            ],
        ], status: $status);
    }
}
