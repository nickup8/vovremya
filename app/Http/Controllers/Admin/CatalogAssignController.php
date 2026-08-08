<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MasterService;
use App\Models\ServiceCatalog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;

class CatalogAssignController extends Controller
{
    public function assign(ServiceCatalog $catalog, User $master): RedirectResponse
    {
        $this->authorize('create', MasterService::class);

        abort_unless($master->is_master, 422, 'Не мастер');

        abort_unless(
            $master->workspace_id === $catalog->workspace_id
            && $catalog->workspace_id === auth()->user()->workspace_id,
            403,
        );

        abort_unless(auth()->user()->role->canManageTeam(), 403);

        MasterService::updateOrCreate(
            ['master_id' => $master->id, 'catalog_id' => $catalog->id],
            ['is_active' => true],
        );

        Log::info('Catalog service assigned', [
            'admin_id' => auth()->id(),
            'master_id' => $master->id,
            'catalog_id' => $catalog->id,
        ]);

        return back()->with('success', "Услуга «{$catalog->title}» назначена мастеру {$master->name}.");
    }

    public function detach(ServiceCatalog $catalog, User $master): RedirectResponse
    {
        abort_unless(
            $master->workspace_id === $catalog->workspace_id
            && $catalog->workspace_id === auth()->user()->workspace_id,
            403,
        );

        abort_unless(auth()->user()->role->canManageTeam(), 403);

        $ms = MasterService::where('master_id', $master->id)
            ->where('catalog_id', $catalog->id)
            ->first();

        if ($ms) {
            $ms->update(['is_active' => false]);
        }

        Log::info('Catalog service detached', [
            'admin_id' => auth()->id(),
            'master_id' => $master->id,
            'catalog_id' => $catalog->id,
        ]);

        return back()->with('success', "Услуга «{$catalog->title}» снята с мастера {$master->name}.");
    }
}
