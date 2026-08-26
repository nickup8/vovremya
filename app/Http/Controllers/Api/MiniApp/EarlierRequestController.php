<?php

namespace App\Http\Controllers\Api\MiniApp;

use App\Enums\AppointmentStatus;
use App\Enums\SlotRequestDeliveryChannel;
use App\Enums\SlotRequestSource;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Client;
use App\Services\SlotRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EarlierRequestController extends Controller
{
    public function __construct(
        private SlotRequestService $slotRequestService,
    ) {}

    public function store(Request $request, Appointment $appointment): JsonResponse
    {
        $client = $this->resolveClient($request, $appointment);

        if ($client === null) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        if (! $appointment->master->isAutoFillEnabled()) {
            return response()->json(['error' => 'autofill_disabled'], 422);
        }

        $validated = $request->validate([
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d'],
            'time_from' => ['required', 'regex:/^\d{2}:\d{2}(:\d{2})?$/'],
            'time_to' => ['required', 'regex:/^\d{2}:\d{2}(:\d{2})?$/'],
        ]);

        $timeFrom = strlen($validated['time_from']) === 5 ? $validated['time_from'] . ':00' : $validated['time_from'];
        $timeTo = strlen($validated['time_to']) === 5 ? $validated['time_to'] . ':00' : $validated['time_to'];

        try {
            $slotRequest = $this->slotRequestService->createOrUpdateEarlierRequest(
                appointment: $appointment,
                client: $client,
                dateFrom: $validated['date_from'],
                dateTo: $validated['date_to'],
                timeFrom: $timeFrom,
                timeTo: $timeTo,
                deliveryChannel: SlotRequestDeliveryChannel::Max,
                requestSource: SlotRequestSource::Max,
            );

            return response()->json([
                'ok' => true,
                'earlier_request' => [
                    'id' => $slotRequest->id,
                    'date_from' => $slotRequest->date_from->format('Y-m-d'),
                    'date_to' => $slotRequest->date_to->format('Y-m-d'),
                    'time_from' => substr($slotRequest->time_from, 0, 5),
                    'time_to' => substr($slotRequest->time_to, 0, 5),
                    'status' => $slotRequest->status->value,
                ],
            ]);
        } catch (\DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function destroy(Request $request, Appointment $appointment): JsonResponse
    {
        $client = $this->resolveClient($request, $appointment);

        if ($client === null) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        $slotRequest = $appointment->activeSlotRequest;

        if ($slotRequest === null) {
            return response()->json(['ok' => true]);
        }

        $this->slotRequestService->cancel($slotRequest, $client);

        return response()->json(['ok' => true]);
    }

    private function resolveClient(Request $request, Appointment $appointment): ?Client
    {
        $maxId = $request->attributes->get('max_init')->userId;

        $clientIds = Client::byMaxId($maxId)->pluck('id');

        if (! $clientIds->contains($appointment->client_id)) {
            return null;
        }

        return $appointment->client;
    }
}
