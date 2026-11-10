<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Services\Auth\SessionManagerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SessionController extends Controller
{
    public function __construct(
        protected SessionManagerService $sessionService
    ) {}

    /**
     * Get the active sessions for the current user.
     */
    public function index(Request $request): JsonResponse
    {
        $sessions = $this->sessionService->getSessions($request->user(), $request);

        return response()->json([
            'success' => true,
            'sessions' => $sessions,
        ]);
    }

    /**
     * Terminate all other sessions across devices.
     */
    public function destroyOther(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!empty($user->password)) {
            $request->validate([
                'password' => ['required', 'string'],
            ], [
                'password.required' => 'ورود کلمه عبور فعلی جهت تایید هویت الزامی است.',
            ]);
        }

        $this->sessionService->terminateOtherSessions(
            $user,
            $request,
            $request->input('password')
        );

        return response()->json([
            'success' => true,
            'message' => 'تمام نشست‌های فعال روی سایر دستگاه‌ها با موفقیت لغو و خارج شدند.',
            'sessions' => $this->sessionService->getSessions($user, $request),
        ]);
    }

    /**
     * Terminate a specific session.
     */
    public function destroy(Request $request, string $sessionId): JsonResponse
    {
        $user = $request->user();

        $this->sessionService->terminateSpecificSession($user, $sessionId, $request);

        return response()->json([
            'success' => true,
            'message' => 'نشست دستگاه مورد نظر با موفقیت لغو گردید.',
            'sessions' => $this->sessionService->getSessions($user, $request),
        ]);
    }
}

