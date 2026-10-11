<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case Participant = 'participant';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrador',
            self::Participant => 'Participante',
        };
    }
}
