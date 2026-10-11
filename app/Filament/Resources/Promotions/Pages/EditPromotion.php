<?php

namespace App\Filament\Resources\Promotions\Pages;

use App\Filament\Resources\Promotions\PromotionActions;
use App\Filament\Resources\Promotions\PromotionResource;
use App\Models\AuditLog;
use App\Models\Promotion;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Arr;

/**
 * @property Promotion $record
 */
class EditPromotion extends EditRecord
{
    protected static string $resource = PromotionResource::class;

    public function getSubheading(): ?string
    {
        $text = 'Situação: '.$this->record->phase()->label();

        if ($this->record->is_demo) {
            $text .= ' · Fictícia (não aparece em produção)';
        }

        return $text;
    }

    protected function getHeaderActions(): array
    {
        [$preview, $publish] = PromotionActions::all();

        return [
            $preview,
            $publish,
            ActionGroup::make(array_slice(PromotionActions::all(), 2))->label('Mais ações')->button(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return PromotionResource::numberingToForm($data);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return PromotionResource::numberingToModel($data);
    }

    protected function afterSave(): void
    {
        $changes = Arr::except($this->record->getChanges(), ['updated_at']);

        if ($changes !== []) {
            AuditLog::record('promotion.updated', $this->record, $changes);
        }
    }
}
