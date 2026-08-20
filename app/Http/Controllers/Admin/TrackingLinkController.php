<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TrackingLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * CRUD tracking-ссылок мастера. Delete отсутствует намеренно (см. ТЗ).
 * Доступ гейтится middleware feature:channel_analytics (ПРОФИ).
 * Ownership: только текущий authenticated master.
 */
class TrackingLinkController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $master = $request->user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $master->trackingLinks()->create([
            'name' => $validated['name'],
            'token' => TrackingLink::generateToken(), // токен только backend
            'is_active' => true,
        ]);

        return back()->with('success', 'Ссылка создана');
    }

    public function update(Request $request, TrackingLink $trackingLink): RedirectResponse
    {
        $this->assertOwner($request, $trackingLink);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $trackingLink->update(['name' => $validated['name']]);

        return back()->with('success', 'Ссылка переименована');
    }

    public function setActive(Request $request, TrackingLink $trackingLink): RedirectResponse
    {
        $this->assertOwner($request, $trackingLink);

        $validated = $request->validate([
            'is_active' => 'required|boolean',
        ]);

        $trackingLink->update(['is_active' => $validated['is_active']]);

        return back()->with('success', $validated['is_active']
            ? 'Ссылка включена'
            : 'Ссылка отключена');
    }

    private function assertOwner(Request $request, TrackingLink $trackingLink): void
    {
        abort_unless($trackingLink->master_id === $request->user()->id, 403);
    }
}
