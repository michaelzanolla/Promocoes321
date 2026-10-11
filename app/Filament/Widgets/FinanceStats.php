<?php

namespace App\Filament\Widgets;

use App\Services\AdminMetrics;
use App\Support\Format;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class FinanceStats extends StatsOverviewWidget
{
    protected static ?int $sort = 3;

    protected ?string $heading = 'Valores';

    protected ?string $description = 'Só entra como recebido o que o provedor de pagamento confirmou. Reservas não são receita.';

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $money = app(AdminMetrics::class)->money();

        return [
            Stat::make('Recebido (confirmado)', Format::brl($money['received']))->color('success'),
            Stat::make('Faturamento bruto', Format::brl($money['gross']))->description('Recebido + reembolsado'),
            Stat::make('Reembolsado', Format::brl($money['refunded'])),
            Stat::make('Pendente', Format::brl($money['pending']))->description('Reservas aguardando pagamento'),
        ];
    }
}
