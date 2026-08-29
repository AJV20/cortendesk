<?php

namespace App\Http\Controllers;

use App\Models\WebClientOsLogin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class WebClientOsLoginController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        if ($blocked = $this->secureTransportProblem($request)) {
            return $blocked;
        }
        $validated = $this->validateInput($request, false);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        $login = WebClientOsLogin::query()
            ->where('user_id', $request->user()->id)
            ->where('peer_id', $validated['peerId'])
            ->first();

        return $this->noStoreJson(
            $login
                ? ['enabled' => true, 'password' => $login->password]
                : ['enabled' => false]
        );
    }

    public function update(Request $request): Response|JsonResponse
    {
        if ($blocked = $this->secureTransportProblem($request)) {
            return $blocked;
        }
        $validated = $this->validateInput($request, true);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }
        $now = now();

        DB::table('webclient_os_logins')->upsert([
            [
                'user_id' => $request->user()->id,
                'peer_id' => $validated['peerId'],
                'password' => Crypt::encryptString($validated['password']),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], ['user_id', 'peer_id'], ['password', 'updated_at']);

        return response()->noContent()->header('Cache-Control', 'no-store, private');
    }

    public function destroy(Request $request): Response|JsonResponse
    {
        if ($blocked = $this->secureTransportProblem($request)) {
            return $blocked;
        }
        $validated = $this->validateInput($request, false);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        WebClientOsLogin::query()
            ->where('user_id', $request->user()->id)
            ->where('peer_id', $validated['peerId'])
            ->delete();

        return response()->noContent()->header('Cache-Control', 'no-store, private');
    }

    private function secureTransportProblem(Request $request): ?JsonResponse
    {
        $configuredScheme = strtolower((string) parse_url((string) config('app.url'), PHP_URL_SCHEME));
        if ($request->isSecure() && $configuredScheme === 'https') {
            return null;
        }
        $loopbackHost = in_array(strtolower($request->getHost()), ['localhost', '127.0.0.1', '::1'], true);
        $loopbackIp = in_array($request->ip(), ['127.0.0.1', '::1'], true);
        if ($loopbackHost && $loopbackIp) {
            return null;
        }

        return $this->noStoreJson(['error' => 'HTTPS is required for OS auto-login.'], 403);
    }

    /** @return array{peerId:string,password?:string}|JsonResponse */
    private function validateInput(Request $request, bool $withPassword): array|JsonResponse
    {
        $rules = ['peerId' => ['required', 'string', 'max:255', 'regex:/\A[A-Za-z0-9_-]+\z/']];
        if ($withPassword) {
            $rules['password'] = ['required', 'string', 'min:1', 'max:1024'];
        }
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return $this->noStoreJson(['error' => 'Invalid OS auto-login settings.'], 422);
        }

        /** @var array{peerId:string,password?:string} $validated */
        $validated = $validator->validated();

        return $validated;
    }

    /** @param array<string, mixed> $body */
    private function noStoreJson(array $body, int $status = 200): JsonResponse
    {
        return response()->json($body, $status)->header('Cache-Control', 'no-store, private');
    }
}
