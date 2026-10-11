<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PromotionPhase;
use App\Enums\PromotionStatus;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Promotion;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Transições de status da promoção (docs/fase-1-planejamento.md, seção 5).
 * Toda transição é validada aqui e registrada no log de auditoria.
 * Erros de regra de negócio são DomainException com mensagem pronta para o usuário.
 */
class PromotionLifecycle
{
    /**
     * Motivos que impedem a publicação. Lista vazia = pode publicar.
     *
     * @return list<string>
     */
    public function publishBlockers(Promotion $promotion): array
    {
        $blockers = [];

        if ($promotion->starts_at === null || $promotion->ends_at === null) {
            $blockers[] = 'Defina a data de início e a de encerramento.';
        } elseif ($promotion->ends_at->lte($promotion->starts_at)) {
            $blockers[] = 'O encerramento precisa ser depois do início.';
        } elseif ($promotion->ends_at->isPast()) {
            $blockers[] = 'A data de encerramento já passou.';
        }

        if (blank($promotion->rules)) {
            $blockers[] = 'Escreva as regras de participação.';
        }

        if (blank($promotion->draw_info)) {
            $blockers[] = 'Explique como o resultado será apurado.';
        }

        if (empty($promotion->payment_methods)) {
            $blockers[] = 'Escolha pelo menos uma forma de pagamento.';
        }

        if ($promotion->max_per_order !== null && $promotion->max_per_order < $promotion->min_per_order) {
            $blockers[] = 'O máximo de números por pedido precisa ser maior ou igual ao mínimo.';
        }

        if (app()->isProduction()) {
            if ($promotion->is_demo) {
                $blockers[] = 'Promoções fictícias não podem ser publicadas em produção.';
            }

            if (blank($promotion->legal_reference)) {
                $blockers[] = 'Preencha a referência legal (autorização ou parecer jurídico) antes de publicar.';
            }
        }

        return $blockers;
    }

    public function publish(Promotion $promotion): void
    {
        $this->expectStatus($promotion, [PromotionStatus::Draft], 'Só rascunhos podem ser publicados.');

        if ($blockers = $this->publishBlockers($promotion)) {
            throw new DomainException(implode(' ', $blockers));
        }

        $this->transition($promotion, PromotionStatus::Published, 'promotion.published');
    }

    public function pause(Promotion $promotion): void
    {
        $this->expectStatus($promotion, [PromotionStatus::Published], 'Só promoções publicadas podem ser pausadas.');
        $this->transition($promotion, PromotionStatus::Paused, 'promotion.paused');
    }

    public function resume(Promotion $promotion): void
    {
        $this->expectStatus($promotion, [PromotionStatus::Paused], 'Só promoções pausadas podem ser retomadas.');
        $this->transition($promotion, PromotionStatus::Published, 'promotion.resumed');
    }

    public function close(Promotion $promotion): void
    {
        $this->expectStatus($promotion, [PromotionStatus::Published, PromotionStatus::Paused], 'Só promoções publicadas ou pausadas podem ser encerradas.');
        $this->transition($promotion, PromotionStatus::Closed, 'promotion.closed');
    }

    public function cancel(Promotion $promotion): void
    {
        $this->expectStatus($promotion, [PromotionStatus::Draft, PromotionStatus::Published, PromotionStatus::Paused, PromotionStatus::Closed], 'Esta promoção não pode mais ser cancelada.');
        $this->transition($promotion, PromotionStatus::Cancelled, 'promotion.cancelled');

        // Reservas ainda não pagas deixam de valer (a conciliação cancela as cobranças no provedor).
        // Pagamento que chegar mesmo assim vai para análise (SyncPayment).
        $promotion->orders()->where('status', OrderStatus::AwaitingPayment)->each(fn (Order $order) => app(CancelOrder::class)($order));
    }

    /**
     * Pedidos que ainda impedem registrar o resultado.
     */
    public function pendingOrdersCount(Promotion $promotion): int
    {
        return $promotion->orders()->whereIn('status', [
            OrderStatus::AwaitingPayment, OrderStatus::Processing, OrderStatus::NeedsReview,
        ])->count();
    }

    /**
     * @param  ?string  $proofPath  arquivo de comprovação já salvo no disco público (foto, print ou PDF da ata)
     */
    public function recordResult(Promotion $promotion, int $number, ?string $proofUrl, ?string $notes, ?string $proofPath = null): void
    {
        if ($promotion->phase() !== PromotionPhase::Closed) {
            throw new DomainException('O resultado só pode ser registrado depois que a promoção for encerrada.');
        }

        if ($number < $promotion->number_start || $number > $promotion->lastNumber()) {
            throw new DomainException('O número sorteado precisa estar entre '.$promotion->formatNumber($promotion->number_start).' e '.$promotion->formatNumber($promotion->lastNumber()).'.');
        }

        if ($pending = $this->pendingOrdersCount($promotion)) {
            throw new DomainException("Ainda há {$pending} pedido(s) aguardando pagamento ou em análise. Resolva-os antes de registrar o resultado.");
        }

        DB::transaction(function () use ($promotion, $number, $proofUrl, $notes, $proofPath) {
            $promotion->forceFill([
                'status' => PromotionStatus::Drawn,
                'result_number' => $number,
                'result_proof_url' => $proofUrl,
                'result_proof_path' => $proofPath,
                'result_notes' => $notes,
                'result_published_at' => now(),
            ])->save();

            AuditLog::record('promotion.result_recorded', $promotion, ['result_number' => $number, 'proof_url' => $proofUrl, 'proof_file' => $proofPath]);
        });
    }

    /**
     * Cria um rascunho com o mesmo conteúdo e as mesmas imagens.
     */
    public function duplicate(Promotion $promotion): Promotion
    {
        return DB::transaction(function () use ($promotion) {
            $copy = $promotion->replicate([
                'slug', 'status', 'result_number', 'result_published_at', 'result_proof_url', 'result_notes', 'created_by',
            ]);
            $copy->title = Str::limit($promotion->title.' (cópia)', 255, '');
            $copy->slug = $this->uniqueSlug($promotion->slug.'-copia');
            $copy->forceFill(['status' => PromotionStatus::Draft, 'created_by' => auth()->id()])->save();

            foreach ($promotion->images as $image) {
                $copy->images()->create($image->only(['path', 'alt', 'position']));
            }

            AuditLog::record('promotion.duplicated', $copy, ['from_id' => $promotion->id]);

            return $copy;
        });
    }

    public function uniqueSlug(string $base, ?int $ignoreId = null): string
    {
        $base = Str::slug($base) ?: 'promocao';
        $slug = $base;

        for ($i = 2; Promotion::where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    /**
     * @param  list<PromotionStatus>  $allowed
     */
    private function expectStatus(Promotion $promotion, array $allowed, string $message): void
    {
        if (! in_array($promotion->status, $allowed, true)) {
            throw new DomainException($message);
        }
    }

    private function transition(Promotion $promotion, PromotionStatus $to, string $action): void
    {
        DB::transaction(function () use ($promotion, $to, $action) {
            $from = $promotion->status;
            $promotion->forceFill(['status' => $to])->save();
            AuditLog::record($action, $promotion, ['from' => $from->value, 'to' => $to->value]);
        });
    }
}
