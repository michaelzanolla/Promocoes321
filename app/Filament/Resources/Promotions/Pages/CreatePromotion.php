<?php

namespace App\Filament\Resources\Promotions\Pages;

use App\Enums\PromotionStatus;
use App\Filament\Resources\Promotions\PromotionResource;
use App\Models\AuditLog;
use App\Models\Promotion;
use App\Services\PromotionLifecycle;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePromotion extends CreateRecord
{
    protected static string $resource = PromotionResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $promotion = new Promotion($data);
        $promotion->slug = app(PromotionLifecycle::class)->uniqueSlug($data['slug']);
        $promotion->forceFill(['status' => PromotionStatus::Draft, 'created_by' => auth()->id()])->save();

        return $promotion;
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return PromotionResource::numberingToModel($data);
    }

    protected function afterCreate(): void
    {
        AuditLog::record('promotion.created', $this->record);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->record]);
    }
}
