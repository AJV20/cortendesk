<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\SerializesUsers;
use App\Http\Controllers\Controller;
use App\Models\ClientToken;
use App\Models\LoginLog;
use App\Models\User;
use App\Services\AppriseNotifications;
use App\Services\OidcService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class ClientAuthController extends Controller
{
    use SerializesUsers;

    /**
     * Provider name advertised to the client and echoed back as `op`.
     * The console has a single OIDC provider, so this is a fixed handle.
     */
    public const CLIENT_PROVIDER = 'sso';

    /**
     * GET /api/login-options — the third-party sign-in buttons the client shows.
     *
     * This response IS the client's SSO UI: the stock app renders a button per
     * entry, so enabling SSO here makes one appear with no client change. The
     * `common-oidc/<json>` form carries a display name for the button; we send
     * a single entry because the console has one provider (spec §2).
     *
     * Must also answer HEAD — the client uses this URL as its TLS probe.
     */
    public function loginOptions(): JsonResponse
    {
        $oidc = app(OidcService::class);

        if (! $oidc->isEnabled()) {
            return response()->json([]);
        }

        // The client renders "Continue with {name}", so `name` carries the
        // display wording — there is no separate label field it reads.
        return response()->json([
            'common-oidc/'.json_encode([['name' => $oidc->clientProviderName()]]),
        ]);
    }

    /** POST /api/login — spec §3. */
    public function login(Request $request): JsonResponse
    {
        $username = (string) $request->input('username', '');
        $password = (string) $request->input('password', '');
        $deviceInfo = (array) $request->input('deviceInfo', []);

        $user = User::where('username', $username)->first();
        $ok = $user !== null
            && $user->is_active
            && $password !== ''
            && Hash::check($password, $user->password);

        LoginLog::create([
            'user_id' => $ok ? $user->id : null,
            'username' => $username,
            'client' => $deviceInfo['type'] ?? 'client',
            'device_id' => $request->input('id'),
            'device_os' => $deviceInfo['os'] ?? null,
            'ip' => $request->ip(),
            'successful' => $ok,
        ]);

        if (! $ok) {
            // Same reasoning as the console form: unauthenticated, repeatable,
            // and delivery is a synchronous HTTP call, so it happens after the
            // response and its cooldown keys on the address alone.
            app(AppriseNotifications::class)->sendAfterResponse(
                'console.login_failed',
                'Failed console login',
                'A RustDesk client sign-in attempt failed.',
                'client-login:'.sha1((string) $request->ip()),
            );

            // The client shows this message verbatim (after i18n lookup).
            return response()->json(['error' => 'Wrong credentials']);
        }

        $token = ClientToken::issue($user, [
            'id' => $request->input('id'),
            'uuid' => $request->input('uuid'),
            'os' => $deviceInfo['os'] ?? null,
            'name' => $deviceInfo['name'] ?? null,
            'type' => $deviceInfo['type'] ?? null,
        ]);

        // AuthBody: access_token, type and user are required by the client.
        return response()->json([
            'access_token' => $token->token,
            'type' => 'access_token',
            'tfa_type' => '',
            'secret' => '',
            'user' => $this->userPayload($user),
        ]);
    }

    /** POST /api/logout — spec §4. Failures are ignored client-side. */
    public function logout(Request $request): JsonResponse
    {
        $request->attributes->get('client_token')?->delete();

        return response()->json((object) []);
    }

    /** POST /api/currentUser — spec §5: UserPayload at top level. */
    public function currentUser(Request $request): JsonResponse
    {
        return response()->json($this->userPayload($request->user()));
    }
}
