<?php

namespace App\Filament\Resources\Promotions;

use App\Enums\OrderStatus;
use App\Enums\PromotionPhase;
use App\Enums\PromotionStatus;
use App\Exceptions\NumbersUnavailable;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Promotion;
use App\Services\BuyersReport;
use App\Services\PromotionLifecycle;
use App\Services\ReserveNumbers;
use Closure;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Ações de status da promoção. As regras ficam em PromotionLifecycle; aqui só a interface.
 */
class PromotionActions
{
    /**
     * @return list<Action>
     */
    public static function all(): array
    {
        return [
            self::preview(),
            self::transition('publish', 'Publicar', Heroicon::OutlinedRocketLaunch, 'success', [PromotionStatus::Draft], 'Promoção publicada.',
                'A promoção ficará visível no site. Preço, numeração e formas de pagamento travam a partir do primeiro pedido.'),
            self::transition('pause', 'Pausar', Heroicon::OutlinedPauseCircle, 'warning', [PromotionStatus::Published], 'Promoção pausada.',
                'Novas reservas e novos pagamentos ficam suspensos. Reservas em andamento continuam valendo até o prazo delas; Pix já gerado e pago nesse prazo é aceito.'),
            self::transition('resume', 'Retomar', Heroicon::OutlinedPlayCircle, 'success', [PromotionStatus::Paused], 'Promoção retomada.'),
            self::transition('close', 'Encerrar', Heroicon::OutlinedLockClosed, 'gray', [PromotionStatus::Published, PromotionStatus::Paused], 'Promoção encerrada.',
                'Ninguém mais poderá reservar números nem gerar pagamento. Pix já gerado e pago dentro do prazo da reserva ainda é aceito.'),
            self::recordResult(),
            self::exportBuyers(),
            self::offlineSales(),
            self::duplicate(),
            self::transition('cancel', 'Cancelar promoção', Heroicon::OutlinedXCircle, 'danger',
                [PromotionStatus::Draft, PromotionStatus::Published, PromotionStatus::Paused, PromotionStatus::Closed], 'Promoção cancelada.',
                fn (Promotion $record) => ($paid = $record->orders()->where('status', OrderStatus::Confirmed)->where('source', '!=', Order::SOURCE_OFFLINE)->count())
                    ? "Esta promoção tem {$paid} pedido(s) pago(s). Eles precisarão ser reembolsados. Esta ação não pode ser desfeita."
                    : 'Esta ação não pode ser desfeita.'),
            DeleteAction::make()->label('Excluir rascunho'),
        ];
    }

    /** Números vendidos fora do site: ficam indisponíveis no site e contam como vendidos. */
    public static function offlineSales(): Action
    {
        return Action::make('offlineSales')->label('Números vendidos fora do site')->icon(Heroicon::OutlinedTicket)->color('gray')
            ->visible(fn (Promotion $record) => in_array($record->phase(), [PromotionPhase::Draft, PromotionPhase::Scheduled, PromotionPhase::Open, PromotionPhase::Paused], true))
            ->modalDescription(fn (Promotion $record) => 'Marque números da faixa do site ('.$record->formatNumber($record->number_start).' a '
                .$record->formatNumber($record->lastNumber()).') que já foram vendidos de outra forma. Eles aparecem como vendidos no site. '
                .'Para desfazer, abra a marcação em Pedidos (origem "Fora do site").')
            ->schema([
                Textarea::make('numbers')->label('Números')->required()->rows(3)
                    ->placeholder('Ex.: 507, 512, 600-650')
                    ->helperText('Separe por vírgula; use hífen para faixas.'),
                TextInput::make('note')->label('Anotação interna (opcional)')->maxLength(255)
                    ->placeholder('Ex.: talão do João, vendidos na feira')
                    ->helperText('Só o administrador vê.'),
            ])
            ->action(function (Promotion $record, array $data) {
                try {
                    $order = app(ReserveNumbers::class)->markSoldOffline($record, ReserveNumbers::parseList($data['numbers']), $data['note'] ?? null);
                    Notification::make()->success()->title($order->quantity === 1 ? '1 número marcado como vendido fora do site.' : "{$order->quantity} números marcados como vendidos fora do site.")->send();
                } catch (NumbersUnavailable $e) {
                    $shown = implode(', ', array_map($record->formatNumber(...), array_slice($e->numbers, 0, 30)));
                    Notification::make()->danger()->title('Nada foi marcado')->persistent()
                        ->body("Estes números já estão reservados ou vendidos no site: {$shown}".(count($e->numbers) > 30 ? ' e outros.' : '.').' Retire-os da lista e tente de novo.')
                        ->send();
                } catch (DomainException $e) {
                    Notification::make()->danger()->title('Nada foi marcado')->body($e->getMessage())->persistent()->send();
                }
            });
    }

    /** Planilha com cada número pago, nome e telefone do comprador. Fica registrado no histórico (dados pessoais). */
    public static function exportBuyers(): Action
    {
        return Action::make('exportBuyers')->label('Exportar compradores')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
            ->visible(fn (Promotion $record) => $record->status !== PromotionStatus::Draft)
            ->action(function (Promotion $record) {
                $path = tempnam(sys_get_temp_dir(), 'compradores');
                $count = app(BuyersReport::class)->writeXlsx($record, $path);
                AuditLog::record('promotion.buyers_exported', $record, ['numeros' => $count]);

                return response()->streamDownload(function () use ($path) {
                    readfile($path);
                    @unlink($path);
                }, 'compradores-'.$record->slug.'-'.now()->format('Y-m-d').'.xlsx');
            });
    }

    public static function preview(): Action
    {
        return Action::make('preview')->label('Ver no site')->icon(Heroicon::OutlinedEye)->color('gray')
            ->url(fn (Promotion $record) => route('promotions.show', $record->slug))
            ->openUrlInNewTab();
    }

    /**
     * @param  list<PromotionStatus>  $from
     * @param  string|Closure(Promotion): string|null  $description
     */
    private static function transition(string $method, string $label, Heroicon $icon, string $color, array $from, string $success, string|Closure|null $description = null): Action
    {
        return Action::make($method)->label($label)->icon($icon)->color($color)
            ->visible(fn (Promotion $record) => in_array($record->status, $from, true))
            ->requiresConfirmation()
            ->modalHeading(fn (Promotion $record) => "{$label}: {$record->title}")
            ->modalDescription($description)
            ->action(fn (Promotion $record) => self::run(fn () => app(PromotionLifecycle::class)->{$method}($record), $success));
    }

    public static function recordResult(): Action
    {
        return Action::make('recordResult')->label('Registrar resultado')->icon(Heroicon::OutlinedTrophy)->color('primary')
            ->visible(fn (Promotion $record) => $record->phase() === PromotionPhase::Closed)
            ->modalDescription('O resultado aparece na página pública da promoção e não pode ser alterado depois.')
            ->schema(fn (Promotion $record) => [
                TextInput::make('number')->label('Número sorteado')->required()->integer()
                    ->minValue($record->seriesStart())->maxValue($record->seriesEnd())
                    ->helperText('Qualquer número da ação, de '.$record->formatNumber($record->seriesStart()).' a '.$record->formatNumber($record->seriesEnd()).', inclusive os vendidos fora do site.'),
                FileUpload::make('proof_file')->label('Arquivo da comprovação (foto, print ou PDF da ata)')
                    ->disk('public')->directory('sorteios')->maxSize(5120)
                    ->acceptedFileTypes([...PromotionResource::IMAGE_TYPES, 'application/pdf'])
                    ->helperText('JPG, PNG, WebP ou PDF, até 5 MB. Fica disponível na página pública da promoção.'),
                TextInput::make('proof_url')->label('Link do sorteio (gravação da transmissão ao vivo, publicação etc.)')->url()->rule('url:http,https')->maxLength(255),
                Textarea::make('notes')->label('Observações')->rows(3),
            ])
            ->action(fn (Promotion $record, array $data) => self::run(
                fn () => app(PromotionLifecycle::class)->recordResult($record, (int) $data['number'], $data['proof_url'] ?? null, $data['notes'] ?? null, $data['proof_file'] ?? null),
                'Resultado registrado.',
            ));
    }

    public static function duplicate(): Action
    {
        return Action::make('duplicate')->label('Duplicar')->icon(Heroicon::OutlinedDocumentDuplicate)->color('gray')
            ->requiresConfirmation()
            ->modalDescription('Cria um rascunho com o mesmo conteúdo, sem pedidos nem resultado.')
            ->action(function (Promotion $record) {
                $copy = app(PromotionLifecycle::class)->duplicate($record);
                Notification::make()->success()->title('Rascunho criado a partir da promoção.')->send();

                return redirect(PromotionResource::getUrl('edit', ['record' => $copy]));
            });
    }

    private static function run(Closure $callback, string $success): void
    {
        try {
            $callback();
            Notification::make()->success()->title($success)->send();
        } catch (DomainException $e) {
            Notification::make()->danger()->title('Não foi possível concluir')->body($e->getMessage())->persistent()->send();
        }
    }
}
