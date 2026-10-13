<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array<string, mixed>|null $changes
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'changes' => 'array',
        ];
    }

    public const ACTION_LABELS = [
        'user.created' => 'Usuário criado',
        'user.updated' => 'Usuário alterado',
        'user.activated' => 'Conta ativada',
        'user.deactivated' => 'Conta desativada',
        'user.promoted_to_admin' => 'Promovido a administrador',
        'promotion.created' => 'Promoção criada',
        'promotion.updated' => 'Promoção alterada',
        'promotion.duplicated' => 'Promoção duplicada',
        'promotion.published' => 'Promoção publicada',
        'promotion.paused' => 'Promoção pausada',
        'promotion.resumed' => 'Promoção retomada',
        'promotion.closed' => 'Promoção encerrada',
        'promotion.cancelled' => 'Promoção cancelada',
        'promotion.result_recorded' => 'Resultado registrado',
        'promotion.buyers_exported' => 'Lista de compradores exportada',
        'settings.updated' => 'Configurações alteradas',
        'order.cancelled' => 'Pedido cancelado pelo painel',
        'order.refund_requested' => 'Reembolso solicitado',
        'order.offline_marked' => 'Números marcados como vendidos fora do site',
        'order.offline_unmarked' => 'Marcação de venda fora do site desfeita',
        'auth.login' => 'Entrou no sistema',
        'auth.failed' => 'Login recusado: e-mail ou senha errados',
        'auth.inactive' => 'Login recusado: conta desativada',
        'auth.two_factor_failed' => 'Login recusado: código de dois fatores errado',
        'auth.throttled' => 'Login bloqueado por excesso de tentativas',
    ];

    public function actionLabel(): string
    {
        return self::ACTION_LABELS[$this->action] ?? $this->action;
    }

    /**
     * @param  array<string, mixed>  $changes
     * @param  ?User  $actor  quem agiu, quando ainda não é o usuário da sessão (ex.: no momento do login)
     */
    public static function record(string $action, ?Model $subject = null, array $changes = [], ?User $actor = null): self
    {
        return self::forceCreate([
            'user_id' => $actor->id ?? auth()->id(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'changes' => $changes ?: null,
            'ip' => app()->runningInConsole() ? null : request()->ip(),
        ]);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
