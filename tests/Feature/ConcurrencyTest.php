<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Promotion;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Prova de que um número não pode ser vendido duas vezes, mesmo com requisições simultâneas.
 *
 * Usa processos PHP de verdade, cada um com sua conexão ao MySQL, disparando no mesmo instante.
 * Por isso não usa RefreshDatabase (as transações de teste esconderiam os dados dos outros processos).
 */
class ConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    /** Os processos gravam de verdade: limpa no fim para não sobrar nada para os outros testes. */
    protected function tearDown(): void
    {
        $this->truncateTablesForAllConnections();

        parent::tearDown();
    }

    /**
     * @param  list<list<int>>  $requests  números pedidos por cada processo
     * @return list<array{ok: bool, numbers: list<int>, conflicts?: list<int>, order?: int, error?: string}>
     */
    private function race(Promotion $promotion, array $requests): array
    {
        $startAt = microtime(true) + 4; // tempo para todos os processos iniciarem o Laravel
        // ponytail: 30 processos no total por execução; alguns ambientes Windows limitam ~34 filhos por processo.
        $env = [
            ...array_filter(['PATH' => getenv('PATH'), 'SystemRoot' => getenv('SystemRoot'), 'TEMP' => getenv('TEMP'), 'TMP' => getenv('TMP')]),
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => config('database.connections.mysql.database'),
            'CACHE_STORE' => 'array',
        ];

        $procs = [];
        foreach ($requests as $numbers) {
            $cmd = [PHP_BINARY, base_path('tests/Concurrency/reserve-worker.php'), (string) $promotion->id, (string) $startAt, implode(',', $numbers)];
            $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
            $procs[] = [$proc, $pipes];
        }

        $results = [];
        foreach ($procs as [$proc, $pipes]) {
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            proc_close($proc);
            $decoded = json_decode((string) $out, true);
            $this->assertIsArray($decoded, "Processo sem resposta válida. Saída: {$out} {$err}");
            $this->assertArrayNotHasKey('error', $decoded, $decoded['error'] ?? '');
            $results[] = $decoded;
        }

        return $results;
    }

    private function assertNoNumberHeldTwice(Promotion $promotion): void
    {
        $duplicates = DB::table('order_items')
            ->where('promotion_id', $promotion->id)->where('active', 1)
            ->groupBy('number')->havingRaw('count(*) > 1')->pluck('number');

        $this->assertCount(0, $duplicates, 'Número preso a dois pedidos: '.$duplicates->join(', '));
    }

    public function test_many_buyers_same_numbers_only_one_wins(): void
    {
        $promotion = Promotion::factory()->open()->create(['total_numbers' => 100]);

        $results = $this->race($promotion, array_fill(0, 10, [7, 8, 9]));

        $winners = array_filter($results, fn ($r) => $r['ok']);
        $this->assertCount(1, $winners, 'Exatamente um comprador deve conseguir os números.');
        foreach (array_filter($results, fn ($r) => ! $r['ok']) as $loser) {
            $this->assertSame([7, 8, 9], $loser['conflicts']);
        }

        $this->assertSame(1, Order::count());
        $this->assertSame(3, OrderItem::where('active', 1)->count());
        $this->assertNoNumberHeldTwice($promotion);
    }

    public function test_overlapping_orders_never_share_a_number_and_never_reserve_partially(): void
    {
        $promotion = Promotion::factory()->open()->create(['total_numbers' => 100]);

        // Processo i pede [i, i+1]: cada número é disputado por dois processos vizinhos.
        $requests = [];
        for ($i = 1; $i <= 12; $i++) {
            $requests[] = [$i, $i + 1];
        }

        $results = $this->race($promotion, $requests);

        $this->assertNoNumberHeldTwice($promotion);

        foreach ($results as $r) {
            $held = OrderItem::where('promotion_id', $promotion->id)->where('active', 1)->whereIn('number', $r['numbers'])->count();

            if ($r['ok']) {
                $order = Order::with('items')->findOrFail($r['order']);
                $this->assertSame($r['numbers'], $order->items->pluck('number')->sort()->values()->all(), 'Pedido vencedor com números diferentes dos pedidos.');
                $this->assertSame(count($r['numbers']) * 500, $order->total_cents);
            } else {
                // Perdedor: tudo ou nada. Os números em conflito estão mesmo ocupados por outro pedido.
                $this->assertNotEmpty($r['conflicts']);
                $this->assertGreaterThan(0, $held);
            }
        }

        // Nenhum pedido ficou pela metade: cada pedido tem exatamente a quantidade que pediu.
        foreach (Order::withCount('items')->get() as $order) {
            $this->assertSame($order->quantity, $order->items_count);
        }
    }

    public function test_numbers_from_an_expired_reservation_go_to_exactly_one_new_buyer(): void
    {
        $promotion = Promotion::factory()->open()->create(['total_numbers' => 100]);
        $stale = Order::factory()->create([
            'promotion_id' => $promotion->id,
            'reserved_until' => now()->subMinute(),
            'status' => OrderStatus::AwaitingPayment,
        ]);
        OrderItem::forceCreate(['order_id' => $stale->id, 'promotion_id' => $promotion->id, 'number' => 50, 'active' => 1]);

        $results = $this->race($promotion, array_fill(0, 8, [50]));

        $this->assertCount(1, array_filter($results, fn ($r) => $r['ok']));
        $this->assertSame(OrderStatus::Expired, $stale->fresh()->status);
        $this->assertNoNumberHeldTwice($promotion);
    }
}
