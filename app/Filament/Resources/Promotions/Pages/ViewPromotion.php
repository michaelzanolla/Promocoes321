<?php

namespace App\Filament\Resources\Promotions\Pages;

use App\Filament\Resources\Promotions\PromotionActions;
use App\Filament\Resources\Promotions\PromotionResource;
use App\Models\Promotion;
use Filament\Resources\Pages\ViewRecord;

/**
 * Somente leitura: é como o administrador abre promoções congeladas (resultado publicado ou canceladas).
 *
 * @property Promotion $record
 */
class ViewPromotion extends ViewRecord
{
    protected static string $resource = PromotionResource::class;

    public function getSubheading(): ?string
    {
        return 'Situação: '.$this->record->phase()->label().($this->record->is_demo ? ' · Fictícia (não aparece em produção)' : '');
    }

    protected function getHeaderActions(): array
    {
        return PromotionActions::all();
    }
}
