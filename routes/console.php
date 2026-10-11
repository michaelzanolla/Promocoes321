<?php

use App\Payments\ReconcilePayments;
use App\Services\ExpireOrders;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('orders:expire', function (ExpireOrders $expireOrders) {
    $count = $expireOrders->expireAllDue();
    $this->info("{$count} pedido(s) expirado(s).");
})->purpose('Expira reservas vencidas e libera os números');

Artisan::command('payments:sync', function (ReconcilePayments $reconcile) {
    $s = $reconcile->run();
    $this->info("Consultadas: {$s['checked']} · canceladas no provedor: {$s['cancelled']} · avisos reprocessados: {$s['events']} · erros: {$s['errors']}");
})->purpose('Concilia pagamentos com o provedor e cancela cobranças de pedidos expirados');

// No servidor: uma linha no cron do cPanel, a cada minuto: php artisan schedule:run
// orders:expire é só limpeza (a reserva expira pedidos vencidos sozinha).
// payments:sync confirma pagamentos sem depender do webhook e cancela cobranças de pedidos expirados.
Schedule::command('orders:expire')->everyMinute()->withoutOverlapping();
Schedule::command('payments:sync')->everyMinute()->withoutOverlapping();
