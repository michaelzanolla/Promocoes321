<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderStatus;
use App\Filament\Resources\Promotions\Pages\EditPromotion;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Promotion;
use App\Models\User;
use App\Services\BuyersReport;
use App\Services\ReserveNumbers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

class BuyersReportTest extends TestCase
{
    use RefreshDatabase;

    private Promotion $promotion;

    protected function setUp(): void
    {
        parent::setUp();
        $this->promotion = Promotion::factory()->open()->create([
            'series_start' => 1, 'series_end' => 1000, 'number_start' => 1, 'total_numbers' => 1000,
        ]);
    }

    private function order(OrderStatus $status, array $numbers, array $attrs = []): Order
    {
        $order = Order::factory()->create(['promotion_id' => $this->promotion->id, 'status' => $status, 'quantity' => count($numbers),
            'confirmed_at' => $status === OrderStatus::Confirmed ? '2026-10-13 14:30:00' : null, ...$attrs]);
        foreach ($numbers as $n) {
            OrderItem::forceCreate(['order_id' => $order->id, 'promotion_id' => $this->promotion->id, 'number' => $n, 'active' => 1]);
        }

        return $order;
    }

    public function test_lists_only_paid_numbers_including_offline_sales(): void
    {
        $paid = $this->order(OrderStatus::Confirmed, [12, 3], ['buyer_name' => 'Maria Silva', 'buyer_phone' => '11987654321', 'buyer_email' => 'maria@exemplo.test']);
        $this->order(OrderStatus::AwaitingPayment, [5]);
        app(ReserveNumbers::class)->markSoldOffline($this->promotion, [7], 'talão do João');

        $rows = app(BuyersReport::class)->rows($this->promotion);

        $this->assertSame(['0003', '0007', '0012'], array_column($rows, 0));
        $this->assertSame(['0003', 'Maria Silva', '11987654321', 'maria@exemplo.test', $paid->public_code, '13/10/2026 14:30'], $rows[0]);
        $this->assertSame('Venda fora do site — talão do João', $rows[1][1]);
    }

    public function test_groups_numbers_by_buyer_email_across_orders(): void
    {
        $this->order(OrderStatus::Confirmed, [12, 3], ['buyer_name' => 'Maria Silva', 'buyer_phone' => null, 'buyer_email' => 'maria@exemplo.test']);
        $this->order(OrderStatus::Confirmed, [20], ['buyer_name' => 'Maria Silva', 'buyer_phone' => '11987654321', 'buyer_email' => 'Maria@Exemplo.test']);
        $this->order(OrderStatus::Confirmed, [8], ['buyer_name' => 'Ana Souza', 'buyer_phone' => '21999990000', 'buyer_email' => 'ana@exemplo.test']);
        app(ReserveNumbers::class)->markSoldOffline($this->promotion, [7, 9], 'talão do João');

        $report = app(BuyersReport::class);
        $buyers = $report->byBuyer($report->rows($this->promotion));

        $this->assertSame([
            ['Ana Souza', '21999990000', 'ana@exemplo.test', 1, '0008'],
            ['Maria Silva', '11987654321', 'maria@exemplo.test', 3, '0003, 0012, 0020'],
            ['Venda fora do site — talão do João', '', '', 2, '0007, 0009'],
        ], $buyers);
    }

    public function test_cells_are_text_so_a_name_never_becomes_a_formula(): void
    {
        $this->order(OrderStatus::Confirmed, [1], ['buyer_name' => '=HYPERLINK("http://mal.test","clique")']);
        $path = tempnam(sys_get_temp_dir(), 'teste');

        app(BuyersReport::class)->writeXlsx($this->promotion, $path);

        $zip = new ZipArchive;
        $zip->open($path);
        $sheets = [$zip->getFromName('xl/worksheets/sheet1.xml'), $zip->getFromName('xl/worksheets/sheet2.xml')];
        $workbook = $zip->getFromName('xl/workbook.xml');
        $zip->close();
        unlink($path);
        $this->assertStringContainsString('name="Por comprador"', $workbook);
        $this->assertStringContainsString('name="Por número"', $workbook);
        foreach ($sheets as $sheet) {
            $this->assertStringNotContainsString('<f>', $sheet);
            $this->assertStringContainsString('HYPERLINK', $sheet);
            // Layout: cabeçalho congelado, filtro no cabeçalho e larguras de coluna.
            $this->assertStringContainsString('<pane ', $sheet);
            $this->assertStringContainsString('<cols>', $sheet);
        }
        $this->assertStringContainsString('<autoFilter ref="A1:E2"', $sheets[0]);
        $this->assertStringContainsString('<autoFilter ref="A1:F2"', $sheets[1]);
    }

    public function test_admin_downloads_the_spreadsheet_and_it_is_logged(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $this->order(OrderStatus::Confirmed, [1]);

        Livewire::test(EditPromotion::class, ['record' => $this->promotion->getRouteKey()])
            ->callAction('exportBuyers')
            ->assertFileDownloaded('compradores-'.$this->promotion->slug.'-'.now()->format('Y-m-d').'.xlsx');

        $this->assertSame(['numeros' => 1], AuditLog::where('action', 'promotion.buyers_exported')->sole()->changes);
    }
}
