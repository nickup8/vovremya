<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MasterService;
use App\Models\ServiceCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class ServiceCatalogController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', ServiceCatalog::class);

        $workspaceId = auth()->user()->workspace_id;

        $items = ServiceCatalog::where('workspace_id', $workspaceId)
            ->orderBy('title')
            ->get();

        return Inertia::render('admin/service-catalog', [
            'catalog' => $items,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', ServiceCatalog::class);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'category' => 'nullable|string|max:255',
            'base_price' => 'required|numeric|min:0',
            'base_duration' => 'required|integer|min:1',
            'is_active' => 'boolean',
        ]);

        $exists = ServiceCatalog::where('workspace_id', auth()->user()->workspace_id)
            ->where('title', $validated['title'])
            ->exists();

        if ($exists) {
            return back()->withErrors(['title' => 'Услуга с таким названием уже есть в каталоге.']);
        }

        $workspace = auth()->user()->workspace;

        $soloMaster = $workspace
            ? $workspace->users()->where('is_master', true)->first()
            : null;

        if (! $soloMaster || $workspace->mastersCount() !== 1) {
            return back()->withErrors(['title' => 'Невозможно определить мастера для этой услуги.']);
        }

        $catalog = ServiceCatalog::create([
            'workspace_id' => auth()->user()->workspace_id,
            'title' => $validated['title'],
            'category' => $validated['category'] ?? null,
            'base_price' => $validated['base_price'],
            'base_duration' => $validated['base_duration'],
            'is_active' => $validated['is_active'] ?? true,
        ]);

        MasterService::firstOrCreate(
            ['master_id' => $soloMaster->id, 'catalog_id' => $catalog->id],
            ['is_active' => true, 'price_override' => null, 'duration_override' => null],
        );

        Log::info('Catalog service created', [
            'admin_id' => auth()->id(),
            'workspace_id' => auth()->user()->workspace_id,
            'title' => $validated['title'],
        ]);

        return back()->with('success', "Услуга «{$validated['title']}» добавлена в каталог.");
    }

    public function update(Request $request, ServiceCatalog $catalog): RedirectResponse
    {
        $this->authorize('update', $catalog);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'category' => 'nullable|string|max:255',
            'base_price' => 'required|numeric|min:0',
            'base_duration' => 'required|integer|min:1',
            'is_active' => 'boolean',
        ]);

        if ($catalog->title !== $validated['title']) {
            $exists = ServiceCatalog::where('workspace_id', auth()->user()->workspace_id)
                ->where('title', $validated['title'])
                ->where('id', '!=', $catalog->id)
                ->exists();

            if ($exists) {
                return back()->withErrors(['title' => 'Услуга с таким названием уже есть в каталоге.']);
            }
        }

        $catalog->update([
            'title' => $validated['title'],
            'category' => $validated['category'] ?? null,
            'base_price' => $validated['base_price'],
            'base_duration' => $validated['base_duration'],
            'is_active' => $validated['is_active'] ?? true,
        ]);

        Log::info('Catalog service updated', [
            'admin_id' => auth()->id(),
            'catalog_id' => $catalog->id,
        ]);

        return back()->with('success', "Услуга «{$catalog->title}» обновлена.");
    }

    public function toggleActive(ServiceCatalog $catalog): RedirectResponse
    {
        $this->authorize('update', $catalog);

        $catalog->update(['is_active' => ! $catalog->is_active]);

        return back();
    }

    public function updateReactivation(Request $request, ServiceCatalog $catalog): RedirectResponse
    {
        $this->authorize('update', $catalog);

        $validated = $request->validate([
            'reactivation_days' => 'nullable|integer|min:1',
        ]);

        $catalog->update([
            'reactivation_days' => $validated['reactivation_days'],
        ]);

        return back()->with('success', 'Настройка возврата обновлена.');
    }

    public function destroy(ServiceCatalog $catalog): RedirectResponse
    {
        $this->authorize('delete', $catalog);

        $title = $catalog->title;
        $catalog->delete();

        Log::info('Catalog service deleted', [
            'admin_id' => auth()->id(),
            'catalog_id' => $catalog->id,
            'title' => $title,
        ]);

        return back()->with('success', "Услуга «{$title}» удалена из каталога.");
    }
}
