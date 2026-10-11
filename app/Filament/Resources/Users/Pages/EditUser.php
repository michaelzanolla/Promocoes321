<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\AuditLog;
use App\Models\User;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [UserResource::toggleActiveAction()];
    }

    /**
     * @param  User  $record
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $record->fill(Arr::except($data, ['role', 'is_active']));

        // Campos desabilitados (a própria conta) não chegam em $data; a checagem repete a regra no servidor.
        if (! UserResource::isSelf($record)) {
            $record->forceFill(Arr::only($data, ['role', 'is_active']));
        }

        if ($record->isDirty('email')) {
            $record->email_verified_at = null;
        }

        $changes = Arr::except($record->getDirty(), ['updated_at', 'password', 'remember_token']);
        $record->save();

        if ($changes !== []) {
            AuditLog::record('user.updated', $record, $changes);
        }

        return $record;
    }
}
