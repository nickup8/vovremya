<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use Illuminate\Http\Request;
use Inertia\Inertia;

class StudioBookingController extends Controller
{
    public function show(string $slug)
    {
        $workspace = Workspace::where('slug', $slug)->firstOrFail();

        $masters = $workspace->users()
            ->where('is_master', true)
            ->select('id', 'name', 'master_slug', 'avatar_url', 'specialty')
            ->get();

        return Inertia::render('booking/studio', [
            'workspace' => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
            ],
            'masters' => $masters,
        ]);
    }
}
