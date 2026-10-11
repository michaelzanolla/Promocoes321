<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Única forma de criar o primeiro administrador: pelo terminal do servidor (SSH).
 * Não existe rota web que promova um usuário a administrador.
 */
class CreateAdmin extends Command
{
    protected $signature = 'app:create-admin {email : E-mail do administrador}';

    protected $description = 'Cria um administrador ou promove um usuário existente';

    public function handle(): int
    {
        $email = (string) $this->argument('email');

        if (Validator::make(['email' => $email], ['email' => 'required|email'])->fails()) {
            $this->error('E-mail inválido.');

            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();

        if ($user) {
            if ($user->isAdmin()) {
                $this->info('Este usuário já é administrador.');

                return self::SUCCESS;
            }

            if (! confirm("Promover {$user->name} <{$email}> a administrador?", default: false)) {
                return self::FAILURE;
            }
        } else {
            $name = text('Nome', required: true);
            $plain = password('Senha', required: true, validate: fn (string $value) => Validator::make(
                ['password' => $value], ['password' => Password::defaults()],
            )->errors()->first('password') ?: null);

            $user = User::create(['name' => $name, 'email' => $email, 'password' => $plain]);
            $user->forceFill(['email_verified_at' => now()]);
        }

        $user->forceFill(['role' => Role::Admin, 'is_active' => true])->save();
        AuditLog::record('user.promoted_to_admin', $user, ['via' => 'console']);

        $this->info("Administrador pronto: {$email}");

        return self::SUCCESS;
    }
}
