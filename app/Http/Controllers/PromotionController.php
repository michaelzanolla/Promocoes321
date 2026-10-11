<?php

namespace App\Http\Controllers;

use App\Models\Promotion;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PromotionController extends Controller
{
    public function show(Request $request, string $slug): View
    {
        $query = Promotion::withNumberCounts()->with('images')->where('slug', $slug);

        // Administradores veem rascunhos e promoções fora do ar como pré-visualização.
        $isAdmin = $request->user()?->can('access-admin') ?? false;
        $promotion = $isAdmin ? $query->firstOrFail() : $query->publiclyVisible()->firstOrFail();

        $preview = $isAdmin && ! Promotion::publiclyVisible()->whereKey($promotion->id)->exists();

        return view('site.promotion', ['promotion' => $promotion, 'preview' => $preview]);
    }
}
