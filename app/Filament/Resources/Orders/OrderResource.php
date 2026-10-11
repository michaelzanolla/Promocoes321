<?php

namespace App\Filament\Resources\Orders;

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Order;
use App\Models\Payment;
use App\Support\Format;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Pedidos: somente consulta e ações controladas (conciliar, reembolsar, cancelar).
 * Pedidos nunca são criados, editados ou apagados pelo painel.
 */
class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static ?string $recordTitleAttribute = 'public_code';

    protected static ?string $modelLabel = 'pedido';

    protected static ?string $pluralModelLabel = 'pedidos';

    protected static ?string $slug = 'pedidos';

    protected static ?int $navigationSort = 2;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Order::where('status', OrderStatus::NeedsReview)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Pedidos em análise';
    }

    public static function statusColor(OrderStatus $status): string
    {
        return match ($status) {
            OrderStatus::Confirmed => 'success',
            OrderStatus::AwaitingPayment, OrderStatus::Processing => 'warning',
            OrderStatus::NeedsReview => 'danger',
            default => 'gray',
        };
    }

    /** CPF parcialmente oculto também no painel: ***.982.247-** */
    public static function maskCpf(?string $cpf): string
    {
        return $cpf ? '***.'.substr($cpf, 3, 3).'.'.substr($cpf, 6, 3).'-**' : '—';
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Section::make('Pedido')->columns(2)->components([
                TextEntry::make('public_code')->label('Código')->copyable(),
                TextEntry::make('status')->label('Situação')->badge()
                    ->formatStateUsing(fn (OrderStatus $state) => $state->label())
                    ->color(fn (OrderStatus $state) => self::statusColor($state)),
                TextEntry::make('review_reason')->label('Motivo da análise')->columnSpanFull()->color('danger')
                    ->visible(fn (Order $record) => $record->status === OrderStatus::NeedsReview),
                TextEntry::make('promotion.title')->label('Promoção')->columnSpanFull(),
                TextEntry::make('total_cents')->label('Total')->formatStateUsing(fn (int $state) => Format::brl($state)),
                TextEntry::make('quantity')->label('Quantidade'),
                TextEntry::make('created_at')->label('Criado em')->dateTime('d/m/Y H:i'),
                TextEntry::make('reserved_until')->label('Reserva até')->dateTime('d/m/Y H:i'),
                TextEntry::make('confirmed_at')->label('Confirmado em')->dateTime('d/m/Y H:i')->placeholder('—'),
                TextEntry::make('receipt_code')->label('Código do comprovante')->placeholder('—'),
                TextEntry::make('numbers')->label('Números')->columnSpanFull()
                    ->state(fn (Order $record) => $record->items()->orderBy('number')->pluck('number')
                        ->map(fn ($n) => $record->promotion->formatNumber((int) $n))->join(', ')),
            ]),
            Section::make('Comprador')->components([
                TextEntry::make('buyer_name')->label('Nome')->placeholder('Ainda não informado'),
                TextEntry::make('buyer_email')->label('E-mail')->placeholder('—')->copyable(),
                TextEntry::make('buyer_cpf')->label('CPF')->formatStateUsing(fn (?string $state) => self::maskCpf($state))->placeholder('—'),
                TextEntry::make('buyer_phone')->label('Telefone')->placeholder('—'),
                TextEntry::make('user.email')->label('Conta no site')->placeholder('Compra como convidado'),
                TextEntry::make('terms_accepted_at')->label('Aceite das regras')->dateTime('d/m/Y H:i')->placeholder('—'),
            ]),
            Section::make('Pagamentos')->columnSpanFull()->components([
                RepeatableEntry::make('payments')->hiddenLabel()->placeholder('Nenhuma cobrança gerada.')->columns(4)->schema([
                    TextEntry::make('method')->label('Forma')->formatStateUsing(fn ($state) => $state->label()),
                    TextEntry::make('status')->label('Situação')->badge()->formatStateUsing(fn ($state) => $state->label()),
                    TextEntry::make('amount_cents')->label('Valor')->formatStateUsing(fn (int $state) => Format::brl($state)),
                    TextEntry::make('provider_status')->label('Status no provedor'),
                    TextEntry::make('provider_payment_id')->label('ID no provedor')->copyable(),
                    TextEntry::make('paid_at')->label('Pago em')->dateTime('d/m/Y H:i')->placeholder('—'),
                    TextEntry::make('last_checked_at')->label('Última consulta')->dateTime('d/m/Y H:i:s')->placeholder('—'),
                    TextEntry::make('failure_reason')->label('Recusa')->placeholder('—')
                        ->visible(fn (Payment $record) => filled($record->failure_reason)),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('public_code')->label('Pedido')->searchable()->fontFamily('mono'),
                TextColumn::make('promotion.title')->label('Promoção')->limit(30),
                TextColumn::make('buyer_name')->label('Comprador')->searchable(['buyer_name', 'buyer_email'])
                    ->description(fn (Order $record) => $record->buyer_email)->placeholder('—'),
                TextColumn::make('quantity')->label('Qtd.'),
                TextColumn::make('total_cents')->label('Total')->formatStateUsing(fn (int $state) => Format::brl($state)),
                TextColumn::make('status')->label('Situação')->badge()
                    ->formatStateUsing(fn (OrderStatus $state) => $state->label())
                    ->color(fn (OrderStatus $state) => self::statusColor($state)),
                TextColumn::make('created_at')->label('Criado em')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Situação')
                    ->options(collect(OrderStatus::cases())->mapWithKeys(fn (OrderStatus $s) => [$s->value => $s->label()])->all()),
                SelectFilter::make('promotion')->label('Promoção')->relationship('promotion', 'title')->searchable(),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'view' => ViewOrder::route('/{record}'),
        ];
    }
}
