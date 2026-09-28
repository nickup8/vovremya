<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MasterService;
use App\Models\User;
use App\Services\Booking\FreeWindowsService;
use App\Services\Booking\FreeWindowPublicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FreeWindowsController extends Controller
{
    public function __construct(
        private readonly FreeWindowsService $freeWindowsService,
        private readonly FreeWindowPublicationService $publicationService,
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

    /**
     * Create or reuse a publication from visible days.
     *
     * POST /admin/free-windows/publications
     */
    public function storePublication(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'mode' => 'required|in:service,all',
            'service_id' => 'nullable|uuid',
            'date_from' => 'required|date_format:Y-m-d',
            'date_to' => 'required|date_format:Y-m-d|after_or_equal:date_from',
            'days' => 'required|array|min:1',
            'days.*.date' => 'required|date_format:Y-m-d',
            'days.*.starts' => 'required_if:mode,service|array',
            'days.*.starts.*' => 'string',
            'days.*.ranges' => 'required_if:mode,all|array',
            'days.*.ranges.*.start' => 'required|string',
            'days.*.ranges.*.end' => 'required|string',
        ]);

        // Validate max range: 14 calendar days
        $dateFrom = \Carbon\Carbon::parse($validated['date_from']);
        $dateTo = \Carbon\Carbon::parse($validated['date_to']);

        if (abs($dateTo->diffInDays($dateFrom)) > 13) {
            throw ValidationException::withMessages([
                'date_to' => 'Максимальный период — 14 календарных дней.',
            ]);
        }

        // Validate service ownership for service mode
        if ($validated['mode'] === 'service') {
            if (empty($validated['service_id'])) {
                throw ValidationException::withMessages([
                    'service_id' => 'Для режима «Услуга» необходимо указать service_id.',
                ]);
            }

            $masterService = MasterService::where('id', $validated['service_id'])
                ->where('master_id', $user->id)
                ->where('is_active', true)
                ->first();

            if (! $masterService) {
                abort(422, 'Услуга не найдена, неактивна или не принадлежит вам.');
            }

            if (! $masterService->catalog || ! $masterService->catalog->is_active) {
                throw ValidationException::withMessages([
                    'service_id' => 'Каталог услуги неактивен.',
                ]);
            }
        }

        // Validate that dates are inside the requested range
        foreach ($validated['days'] as $day) {
            if ($day['date'] < $validated['date_from'] || $day['date'] > $validated['date_to']) {
                throw ValidationException::withMessages([
                    'days' => 'Дата {$day["date"]} выходит за пределы указанного диапазона.',
                ]);
            }

            // Validate HH:mm format for starts
            if ($validated['mode'] === 'service' && isset($day['starts'])) {
                foreach ($day['starts'] as $start) {
                    if (! preg_match('/^\d{2}:\d{2}$/', $start)) {
                        throw ValidationException::withMessages([
                            'days' => 'Неверный формат времени: {$start}.',
                        ]);
                    }
                }
            }

            // Validate ranges: start < end
            if ($validated['mode'] === 'all' && isset($day['ranges'])) {
                foreach ($day['ranges'] as $range) {
                    if (! preg_match('/^\d{2}:\d{2}$/', $range['start']) || ! preg_match('/^\d{2}:\d{2}$/', $range['end'])) {
                        throw ValidationException::withMessages([
                            'days' => 'Неверный формат времени диапазона.',
                        ]);
                    }
                    if ($range['start'] >= $range['end']) {
                        throw ValidationException::withMessages([
                            'days' => 'Время начала должно быть раньше времени окончания.',
                        ]);
                    }
                }
            }
        }

        $publication = $this->publicationService->createOrReuse(
            $user,
            $validated['mode'],
            $validated['service_id'] ?? null,
            $validated['date_from'],
            $validated['date_to'],
            $validated['days'],
        );

        $url = $this->publicationService->buildPublicationUrl($publication);

        return response()->json([
            'token' => $publication->token,
            'url' => $url,
            'mode' => $publication->mode,
            'expires_at' => $publication->expires_at->toIso8601String(),
        ]);
    }
}
