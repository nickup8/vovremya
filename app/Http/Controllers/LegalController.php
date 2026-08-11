<?php

namespace App\Http\Controllers;

use Inertia\Inertia;

class LegalController extends Controller
{
    public function offer()
    {
        return Inertia::render('legal/offer');
    }

    public function privacy()
    {
        return Inertia::render('legal/privacy');
    }
}
