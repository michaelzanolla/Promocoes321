<?php

namespace App\Enums;

/**
 * Estados gravados no banco. "Agendada", "Aberta" e "Encerrada" de uma promoção
 * publicada são derivados das datas (ver docs/fase-1-planejamento.md, seção 5).
 */
enum PromotionStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Paused = 'paused';
    case Closed = 'closed';
    case Drawn = 'drawn';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Rascunho',
            self::Published => 'Publicada',
            self::Paused => 'Pausada',
            self::Closed => 'Encerrada',
            self::Drawn => 'Resultado publicado',
            self::Cancelled => 'Cancelada',
        };
    }
}
