<?php

namespace App\Filament\Widgets;

use App\Services\AdminMetrics;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OrderStats extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected ?string $heading = 'Pedidos';

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $orders = app(AdminMetrics::class)->orders();

        return [
            Stat::make('Confirmados', $orders['confirmed']),
            Stat::make('Aguardando pagamento', $orders['pending']),
            Stat::make('Cancelados ou expirados', $orders['cancelled_or_expired']),
            Stat::make('Em análise', $orders['needs_review'])
                ->description('Precisam de decisão manual')
                ->color($orders['needs_review'] > 0 ? 'danger' : null),
        ];
    }
}
