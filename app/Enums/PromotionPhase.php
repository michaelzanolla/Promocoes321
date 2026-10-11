<?php

namespace App\Enums;

/**
 * Situação exibida ao público: combina o status gravado com as datas da promoção.
 */
enum PromotionPhase: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Open = 'open';
    case Paused = 'paused';
    case Closed = 'closed';
    case Drawn = 'drawn';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Rascunho',
            self::Scheduled => 'Em breve',
            self::Open => 'Aberta',
            self::Paused => 'Pausada',
            self::Closed => 'Encerrada',
            self::Drawn => 'Resultado publicado',
            self::Cancelled => 'Cancelada',
        };
    }

    /**
     * Fases que aparecem no filtro público.
     *
     * @return list<self>
     */
    public static function filterable(): array
    {
        return [self::Open, self::Scheduled, self::Closed, self::Drawn];
    }
}
