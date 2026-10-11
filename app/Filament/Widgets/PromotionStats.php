<?php

namespace App\Filament\Widgets;

use App\Services\AdminMetrics;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PromotionStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $heading = 'Promoções e números';

    protected function getStats(): array
    {
        $metrics = app(AdminMetrics::class);
        $promotions = $metrics->promotions();
        $numbers = $metrics->numbers();

        return [
            Stat::make('Promoções abertas', $promotions['open'])
                ->description("{$promotions['total']} no total · {$promotions['finished']} encerradas"),
            Stat::make('Números vendidos e pagos', number_format($numbers['sold'], 0, ',', '.')),
            Stat::make('Números reservados agora', number_format($numbers['reserved'], 0, ',', '.'))
                ->description('Aguardando pagamento; podem voltar a ficar livres'),
        ];
    }
}
