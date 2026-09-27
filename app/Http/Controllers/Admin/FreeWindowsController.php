<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MasterService;
use App\Models\User;
use App\Services\Booking\FreeWindowsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FreeWindowsController extends Controller
{
    public function __construct(
        private readonly FreeWindowsService $freeWindowsService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'service_id' => 'nullable|uuid|exists:master_service,id',
            'date_from' => 'required|date_format:Y-m-d',
            'date_to' => 'required|date_format:Y-m-d|after_or_equal:date_from',
        ]);

        // Validate max range: 14 calendar days
        $dateFrom = \Carbon\Carbon::parse($validated['date_from']);
        $dateTo = \Carbon\Carbon::parse($validated['date_to']);

        if (abs($dateTo->diffInDays($dateFrom)) > 13) {
            throw ValidationException::withMessages([
                'date_to' => 'Максимальный период — 14 календарных дней.',
            ]);
        }

        $user = $request->user();

        // Validate service ownership and active status
        if (! empty($validated['service_id'])) {
            $masterService = MasterService::where('id', $validated['service_id'])
                ->where('master_id', $user->id)
                ->first();

            if (! $masterService) {
                abort(403, 'Услуга не найдена или не принадлежит вам.');
            }

            if (! $masterService->is_active) {
                throw ValidationException::withMessages([
                    'service_id' => 'Услуга неактивна.',
                ]);
            }

            if (! $masterService->catalog || ! $masterService->catalog->is_active) {
                throw ValidationException::withMessages([
                    'service_id' => 'Каталог услуги неактивен.',
                ]);
            }
        }

        $result = $this->freeWindowsService->getFreeWindows(
            $user,
            $validated['date_from'],
            $validated['date_to'],
            $validated['service_id'] ?? null,
        );

        return response()->json($result);
    }
}
