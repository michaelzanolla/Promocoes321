<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Promotion;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Dados de demonstração, só para desenvolvimento.
 * As senhas são geradas na hora e exibidas apenas no terminal: nada fica gravado no repositório.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Os dados de demonstração não podem ser criados em produção.');
        }

        $admin = $this->user('Administrador (demo)', 'admin@demo.test', Role::Admin);
        $this->user('Participante (demo)', 'participante@demo.test', Role::Participant);

        $promotions = [
            ['Smartphone novo', 'Eletrônicos', 1000, 500, 0],
            ['Vale-compras de R$ 500', 'Diversos', 200, 1000, 1],
            ['Fim de semana na serra', 'Experiências', 500, 2000, 1],
        ];

        foreach ($promotions as [$prize, $category, $total, $price, $start]) {
            $title = "[FICTÍCIA] {$prize}";

            Promotion::factory()->open()->create([
                'slug' => Str::slug($title),
                'title' => $title,
                'summary' => 'Promoção fictícia, criada apenas para testar a plataforma.',
                'description' => "Esta promoção existe só no ambiente de desenvolvimento.\n\nUse-a para testar a escolha de números, o pagamento no ambiente de testes e o comprovante.",
                'prize' => "{$prize} (fictício)",
                'category' => $category,
                'total_numbers' => $total,
                'unit_price_cents' => $price,
                'number_start' => $start,
                'payment_methods' => ['pix', 'credit_card'],
                'rules' => 'Promoção de demonstração. Não há prêmio real.',
                'draw_info' => 'Sem apuração: promoção de demonstração.',
                'is_demo' => true,
                'created_by' => $admin->id,
            ]);
        }

        Promotion::factory()->create([
            'slug' => 'ficticia-rascunho',
            'title' => '[FICTÍCIA] Rascunho de exemplo',
            'is_demo' => true,
        ]);
    }

    private function user(string $name, string $email, Role $role): User
    {
        $password = Str::password(16);

        $user = User::updateOrCreate(['email' => $email], ['name' => $name, 'password' => $password]);
        $user->forceFill(['role' => $role, 'is_active' => true, 'email_verified_at' => now()])->save();

        $this->command->info("{$role->label()}: {$email} / senha: {$password}");

        return $user;
    }
}
