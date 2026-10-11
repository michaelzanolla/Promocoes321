<?php

namespace Database\Seeders;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PromotionStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Promotion;
use App\Models\User;
use App\Payments\Charge;
use App\Payments\SyncPayment;
use App\Services\CancelOrder;
use App\Services\ExpireOrders;
use App\Services\PromotionLifecycle;
use App\Services\ReserveNumbers;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Uma promoção fictícia em cada situação (rascunho, em breve, aberta, pausada, encerrada,
 * resultado publicado, cancelada) e pedidos em todas as situações.
 *
 * Tudo passa pelos serviços do sistema (reserva, confirmação, reembolso, expiração, encerramento,
 * resultado), como aconteceria de verdade. Os pagamentos são simulados: os códigos "pay_demo_*"
 * não existem no Asaas, então o pedido "em processamento" gera erro de consulta no log.
 */
class StatusExamplesSeeder extends Seeder
{
    private const DEMO_CPF = '52998224725';

    public function __construct(
        private ReserveNumbers $reserve,
        private SyncPayment $sync,
        private PromotionLifecycle $lifecycle,
    ) {}

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Os dados de demonstração não podem ser criados em produção.');
        }

        $participant = User::where('email', 'participante@demo.test')->first();

        // Datas longe o bastante para o exemplo não mudar de situação sozinho com o tempo.
        $this->promotion('Em breve: bicicleta aro 29', ['starts_at' => now()->addYear(), 'ends_at' => now()->addYear()->addDays(30)]);

        // Aberta, com um pedido em cada situação. Os do participante aparecem em "Minhas compras".
        $open = $this->promotion('Pedidos em todas as situações');
        // Reserva de 1 ano (só no exemplo), para continuar "aguardando pagamento" em vez de expirar.
        $this->order($open, [1, 2], OrderStatus::AwaitingPayment, $participant)->forceFill(['reserved_until' => now()->addYear()])->save();
        $this->order($open, [3], OrderStatus::Processing, $participant);
        $this->order($open, [4, 5, 6], OrderStatus::Confirmed, $participant);
        $this->order($open, [7], OrderStatus::Expired, $participant);
        $this->order($open, [8], OrderStatus::Cancelled);
        $this->order($open, [9, 10], OrderStatus::Refunded, $participant);
        $this->order($open, [11], OrderStatus::NeedsReview);

        $paused = $this->promotion('Pausada: caixa de som');
        $this->order($paused, [10, 20], OrderStatus::Confirmed);
        $this->lifecycle->pause($paused);

        $closed = $this->promotion('Encerrada pelo administrador: cafeteira');
        $this->order($closed, [15], OrderStatus::Confirmed);
        $this->lifecycle->close($closed);

        // Publicada, mas a data de encerramento já passou: aparece como encerrada sem ninguém mexer.
        $ended = $this->promotion('Encerrada pela data: kit churrasco');
        $this->order($ended, [30, 31], OrderStatus::Confirmed);
        $ended->forceFill(['starts_at' => now()->subDays(30), 'ends_at' => now()->subHour()])->save();

        $drawn = $this->promotion('Resultado publicado: smart TV');
        $this->order($drawn, [42], OrderStatus::Confirmed, $participant);
        $this->order($drawn, [7, 77], OrderStatus::Confirmed);
        $this->order($drawn, [50], OrderStatus::Refunded);
        $this->lifecycle->close($drawn);
        $this->lifecycle->recordResult($drawn, 42, null, 'Sorteio presencial com duas testemunhas (exemplo fictício).', $this->fakeDrawRecord());

        // Cancelada com um pedido pago ainda sem reembolso: é o que o administrador precisaria resolver.
        $cancelled = $this->promotion('Cancelada: fone de ouvido');
        $this->order($cancelled, [3], OrderStatus::Confirmed);
        $this->order($cancelled, [4], OrderStatus::Refunded);
        $this->lifecycle->cancel($cancelled);
    }

    /**
     * Começa sempre aberta (é quando aceita pedidos); a situação final vem depois.
     *
     * @param  array{starts_at?: CarbonImmutable, ends_at?: CarbonImmutable}  $dates
     */
    private function promotion(string $name, array $dates = []): Promotion
    {
        $title = "[FICTÍCIA] {$name}";

        return Promotion::factory()->create([
            'slug' => Str::slug($title),
            'title' => $title,
            'summary' => 'Exemplo de situação, criado para testar a plataforma.',
            'description' => 'Promoção de demonstração. Não há prêmio real.',
            'prize' => "{$name} (fictício)",
            'unit_price_cents' => 1000,
            'total_numbers' => 100,
            'payment_methods' => ['pix', 'credit_card'],
            'rules' => 'Promoção de demonstração. Não há prêmio real.',
            'draw_info' => 'Sem apuração real: promoção de demonstração.',
            'is_demo' => true,
            'status' => PromotionStatus::Published,
            'starts_at' => $dates['starts_at'] ?? now()->subDays(2),
            'ends_at' => $dates['ends_at'] ?? now()->addYear(),
        ]);
    }

    /**
     * @param  list<int>  $numbers
     */
    private function order(Promotion $promotion, array $numbers, OrderStatus $status, ?User $user = null): Order
    {
        ['order' => $order] = $this->reserve->reserve($promotion, $numbers, (string) Str::uuid(), $user);

        $method = $status === OrderStatus::Processing ? PaymentMethod::CreditCard : PaymentMethod::Pix;
        $order->forceFill([
            'buyer_name' => $user->name ?? fake('pt_BR')->name(),
            'buyer_email' => $user->email ?? fake()->unique()->safeEmail(),
            'buyer_cpf' => self::DEMO_CPF,
            'payment_method' => $method,
            'terms_accepted_at' => now(),
        ])->save();

        match ($status) {
            OrderStatus::AwaitingPayment => null,
            OrderStatus::Expired => $this->expire($order),
            OrderStatus::Cancelled => app(CancelOrder::class)($order),
            // Cartão em análise de risco no provedor.
            OrderStatus::Processing => $this->charge($order, $method, PaymentStatus::Pending, 'AWAITING_RISK_ANALYSIS', inRiskAnalysis: true),
            OrderStatus::Confirmed => $this->charge($order, $method, PaymentStatus::Confirmed, 'RECEIVED'),
            OrderStatus::Refunded => $this->charge($this->charge($order, $method, PaymentStatus::Confirmed, 'RECEIVED'), $method, PaymentStatus::Refunded, 'REFUNDED'),
            // Valor pago diferente do total: o sistema não confirma e manda para análise.
            OrderStatus::NeedsReview => $this->charge($order, $method, PaymentStatus::Confirmed, 'RECEIVED', amount: $order->total_cents - 100),
        };

        $order->refresh();
        if ($order->status !== $status) {
            throw new RuntimeException("Exemplo {$order->public_code} ficou como {$order->status->value}, esperado {$status->value}.");
        }

        return $order;
    }

    /** Ata fictícia em PDF, como o administrador anexaria ao registrar o resultado. */
    private function fakeDrawRecord(): string
    {
        $path = 'sorteios/exemplo-ata-ficticia.pdf';
        Storage::disk('public')->put($path, Pdf::loadHTML(
            '<h1>Ata do sorteio (FICTÍCIA)</h1><p>Promoção de demonstração. Número sorteado: 042.</p>'
            .'<p>Documento de exemplo, sem valor: não houve sorteio real.</p>'
        )->output());

        return $path;
    }

    private function expire(Order $order): void
    {
        $order->forceFill(['reserved_until' => now()->subMinute()])->save();
        app(ExpireOrders::class)->expireAllDue();
    }

    /** Aplica uma situação do provedor pelo mesmo caminho do webhook e da conciliação. */
    private function charge(Order $order, PaymentMethod $method, PaymentStatus $status, string $providerStatus, bool $inRiskAnalysis = false, ?int $amount = null): Order
    {
        $payment = Payment::where('order_id', $order->id)->first() ?? Payment::forceCreate([
            'order_id' => $order->id,
            'provider' => 'asaas',
            'provider_payment_id' => 'pay_demo_'.Str::lower($order->public_code),
            'method' => $method,
            'amount_cents' => $order->total_cents,
            'status' => PaymentStatus::Pending,
        ]);

        $this->sync->apply($payment, new Charge(
            id: $payment->provider_payment_id,
            status: $status,
            providerStatus: $providerStatus,
            amountCents: $amount ?? $order->total_cents,
            externalReference: $order->public_code,
            inRiskAnalysis: $inRiskAnalysis,
            paidAt: $status === PaymentStatus::Confirmed ? now() : null,
        ));

        return $order->refresh();
    }
}
