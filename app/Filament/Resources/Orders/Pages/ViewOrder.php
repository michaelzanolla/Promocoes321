<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\AuditLog;
use App\Models\Order;
use App\Payments\PaymentGatewayException;
use App\Payments\ReconcilePayments;
use App\Payments\RefundOrder;
use App\Services\CancelOrder;
use DomainException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

/**
 * @property Order $record
 */
class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('open')->label('Ver página do pedido')->icon(Heroicon::OutlinedEye)->color('gray')
                ->url(fn () => route('orders.show', $this->record->public_code))->openUrlInNewTab(),

            Action::make('sync')->label('Consultar no Asaas')->icon(Heroicon::OutlinedArrowPath)->color('gray')
                ->visible(fn () => $this->record->payments()->exists())
                ->action(function (ReconcilePayments $reconcile) {
                    $this->guard(function () use ($reconcile) {
                        foreach ($this->record->payments as $payment) {
                            $reconcile->check($payment);
                        }
                    }, 'Situação atualizada com o provedor.');
                }),

            Action::make('refund')->label('Reembolsar')->icon(Heroicon::OutlinedReceiptRefund)->color('danger')
                ->visible(fn () => in_array($this->record->status, [OrderStatus::Confirmed, OrderStatus::NeedsReview], true)
                    && $this->record->payments()->where('status', PaymentStatus::Confirmed)->exists())
                ->requiresConfirmation()
                ->modalDescription('O valor integral será devolvido ao comprador pelo provedor. Os números deste pedido não voltam sozinhos para a venda.')
                ->action(fn (RefundOrder $refund) => $this->guard(fn () => $refund($this->record), 'Reembolso solicitado ao provedor.')),

            Action::make('cancel')->label('Cancelar pedido')->icon(Heroicon::OutlinedXCircle)->color('danger')
                ->visible(fn () => $this->record->status === OrderStatus::AwaitingPayment)
                ->requiresConfirmation()
                ->modalDescription('Os números voltam a ficar disponíveis. A cobrança em aberto é cancelada no provedor pela conciliação.')
                ->action(fn (CancelOrder $cancel) => $this->guard(function () use ($cancel) {
                    $cancel($this->record);
                    AuditLog::record('order.cancelled', $this->record);
                }, 'Pedido cancelado.')),
        ];
    }

    private function guard(callable $callback, string $success): void
    {
        try {
            $callback();
            $this->record->refresh();
            Notification::make()->success()->title($success)->send();
        } catch (DomainException $e) {
            Notification::make()->danger()->title('Não foi possível concluir')->body($e->getMessage())->send();
        } catch (PaymentGatewayException $e) {
            report($e);
            Notification::make()->danger()->title('O provedor de pagamento não respondeu')->body($e->getMessage())->persistent()->send();
        }
    }
}
