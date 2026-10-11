<?php

namespace App\Http\Controllers;

use App\Models\Promotion;
use Illuminate\View\View;

class PromotionController extends Controller
{
    public function show(string $slug): View
    {
        $promotion = Promotion::publiclyVisible()
            ->withNumberCounts()
            ->with('images')
            ->where('slug', $slug)
            ->firstOrFail();

        return view('site.promotion', ['promotion' => $promotion]);
    }
}
