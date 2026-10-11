<?php

use App\Http\Controllers\AsaasWebhookController;
use App\Http\Controllers\NumberSelectionController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PromotionController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'site.home')->name('home');
Route::get('promocoes/{slug}', [PromotionController::class, 'show'])->name('promotions.show');
Route::get('paginas/{page}', PageController::class)
    ->whereIn('page', array_keys(PageController::PAGES))
    ->name('pages.show');

// Escolha de números e reserva.
Route::get('promocoes/{slug}/numeros', [NumberSelectionController::class, 'show'])->name('numbers.show');
Route::get('promocoes/{slug}/numeros/situacao', [NumberSelectionController::class, 'status'])
    ->middleware('throttle:numbers-status')->name('numbers.status');
Route::post('promocoes/{slug}/reservar', [NumberSelectionController::class, 'reserve'])
    ->middleware('throttle:reserve')->name('numbers.reserve');

// Pedido (acesso: dono logado, administrador, ou sessão de quem reservou / abriu o link secreto).
Route::get('pedido/{code}', [OrderController::class, 'show'])->middleware('throttle:orders')->name('orders.show');
Route::put('pedido/{code}/dados', [OrderController::class, 'identify'])->middleware('throttle:orders')->name('orders.identify');
Route::post('pedido/{code}/cancelar', [OrderController::class, 'cancel'])->middleware('throttle:orders')->name('orders.cancel');
Route::post('pedido/{code}/pagamento', [OrderController::class, 'pay'])->middleware('throttle:orders')->name('orders.pay');
Route::get('pedido/{code}/situacao', [OrderController::class, 'status'])->middleware('throttle:order-status')->name('orders.status');

// Avisos do provedor de pagamento (sem CSRF; autenticados pelo token do webhook).
Route::post('webhooks/asaas', AsaasWebhookController::class)->name('webhooks.asaas');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
