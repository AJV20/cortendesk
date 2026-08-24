<?php

namespace App\Http\Controllers;

use App\Services\FleetDiagnostics;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function live(): JsonResponse
    {
        return response()->json(['live' => true]);
    }

    public function ready(FleetDiagnostics $diagnostics): JsonResponse
    {
        $readiness = $diagnostics->readiness();

        return response()->json($readiness, $readiness['ready'] ? 200 : 503);
    }
}
