<?php

use App\Services\ExpireOrders;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('orders:expire', function (ExpireOrders $expireOrders) {
    $count = $expireOrders->expireAllDue();
    $this->info("{$count} pedido(s) expirado(s).");
})->purpose('Expira reservas vencidas e libera os números');

// No servidor: uma linha no cron do cPanel, a cada minuto: php artisan schedule:run
// A corretude não depende disto (a reserva expira pedidos vencidos sozinha); é só limpeza.
Schedule::command('orders:expire')->everyMinute()->withoutOverlapping();
