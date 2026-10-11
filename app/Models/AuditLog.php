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
        'settings.updated' => 'Configurações alteradas',
        'order.cancelled' => 'Pedido cancelado pelo painel',
        'order.refund_requested' => 'Reembolso solicitado',
    ];

    public function actionLabel(): string
    {
        return self::ACTION_LABELS[$this->action] ?? $this->action;
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public static function record(string $action, ?Model $subject = null, array $changes = []): self
    {
        return self::forceCreate([
            'user_id' => auth()->id(),
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
