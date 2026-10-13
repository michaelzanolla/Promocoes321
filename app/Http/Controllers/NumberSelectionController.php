<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Enums\PromotionPhase;
use App\Exceptions\NumbersUnavailable;
use App\Models\OrderItem;
use App\Models\Promotion;
use App\Services\ReserveNumbers;
use App\Support\ClientKey;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class NumberSelectionController extends Controller
{
    public function show(string $slug): View|RedirectResponse
    {
        $promotion = $this->find($slug);

        if ($promotion->phase() !== PromotionPhase::Open) {
            return redirect()->route('promotions.show', $promotion->slug);
        }

        return view('site.numbers', ['promotion' => $promotion]);
    }

    /**
     * Números ocupados agora. Só informativo: quem decide é a reserva no servidor.
     * Cache curto para aguentar muitas telas abertas consultando ao mesmo tempo.
     */
    public function status(string $slug): JsonResponse
    {
        $promotion = $this->find($slug);

        $data = Cache::remember("promotion:{$promotion->id}:numbers", 3, function () use ($promotion) {
            $sold = [];
            $reserved = [];

            OrderItem::taken($promotion->id)
                ->select('order_items.number', 'orders.status')
                ->toBase()
                ->orderBy('order_items.number')
                ->each(function ($row) use (&$sold, &$reserved) {
                    $row->status === OrderStatus::Confirmed->value ? $sold[] = (int) $row->number : $reserved[] = (int) $row->number;
                }, 2000);

            return ['sold' => $sold, 'reserved' => $reserved, 'open' => $promotion->phase() === PromotionPhase::Open, 'at' => now()->toIso8601String()];
        });

        return response()->json($data);
    }

    public function reserve(Request $request, string $slug, ReserveNumbers $reserveNumbers): JsonResponse
    {
        $promotion = $this->find($slug);

        $validated = $request->validate([
            'numbers' => ['required', 'array', 'max:'.ReserveNumbers::HARD_MAX_PER_ORDER],
            'numbers.*' => ['integer'],
            'checkout_key' => ['required', 'uuid'],
        ]);

        try {
            ['order' => $order] = $reserveNumbers->reserve($promotion, $validated['numbers'], $validated['checkout_key'], $request->user(), ClientKey::of($request->ip()));
        } catch (NumbersUnavailable $e) {
            Cache::forget("promotion:{$promotion->id}:numbers");

            return response()->json([
                'message' => count($e->numbers) === 1
                    ? 'O número '.$promotion->formatNumber($e->numbers[0]).' acabou de ser reservado por outra pessoa. Nada foi reservado: revise sua seleção.'
                    : 'Alguns números acabaram de ser reservados por outras pessoas. Nada foi reservado: revise sua seleção.',
                'conflicts' => $e->numbers,
            ], 409);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        Cache::forget("promotion:{$promotion->id}:numbers");
        $order->grantAccessInSession($request->session());

        return response()->json(['redirect' => route('orders.show', $order->public_code)]);
    }

    private function find(string $slug): Promotion
    {
        return Promotion::publiclyVisible()->where('slug', $slug)->firstOrFail();
    }
}
