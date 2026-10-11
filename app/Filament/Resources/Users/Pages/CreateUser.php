<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\AuditLog;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * O administrador nunca define nem vê a senha: a conta nasce com uma senha aleatória
     * e a pessoa recebe por e-mail o link para criar a própria senha.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $user = new User([...$data, 'password' => Str::password(32)]);
        $user->forceFill(['role' => $data['role'], 'is_active' => $data['is_active']])->save();

        return $user;
    }

    protected function afterCreate(): void
    {
        /** @var User $user */
        $user = $this->record;

        AuditLog::record('user.created', $user, ['role' => $user->role->value, 'is_active' => $user->is_active]);

        $status = Password::sendResetLink(['email' => $user->email]);

        if ($status !== Password::RESET_LINK_SENT) {
            Notification::make()->warning()->title('Conta criada, mas o e-mail para definir a senha não foi enviado.')
                ->body('Peça para a pessoa usar "Esqueceu a sua senha?" na tela de login.')->persistent()->send();
        }
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Usuário criado. Enviamos um e-mail para a pessoa definir a senha.';
    }
}
