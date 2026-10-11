<?php

/**
 * Processo isolado usado pelo teste de concorrência (tests/Feature/ConcurrencyTest.php).
 * Uso: php reserve-worker.php {promotionId} {inicio_em_microtime} {numeros_separados_por_virgula}
 * Espera até o instante combinado e tenta reservar, para que todos os processos disputem juntos.
 */

use App\Exceptions\NumbersUnavailable;
use App\Models\Promotion;
use App\Services\ReserveNumbers;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $promotionId, $startAt, $csv] = $argv;
$promotion = Promotion::findOrFail((int) $promotionId);
$numbers = array_map('intval', explode(',', $csv));

while (microtime(true) < (float) $startAt) {
    usleep(500);
}

try {
    $result = app(ReserveNumbers::class)->reserve($promotion, $numbers, (string) Str::uuid());
    echo json_encode(['ok' => true, 'order' => $result['order']->id, 'numbers' => $numbers]);
} catch (NumbersUnavailable $e) {
    echo json_encode(['ok' => false, 'conflicts' => $e->numbers, 'numbers' => $numbers]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e::class.': '.$e->getMessage(), 'numbers' => $numbers]);
}
