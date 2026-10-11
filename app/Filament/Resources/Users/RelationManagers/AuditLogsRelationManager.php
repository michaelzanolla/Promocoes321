<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Filament\Resources\AuditLogs\AuditLogResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

/**
 * Histórico de atividades: ações administrativas feitas por este usuário.
 */
class AuditLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'auditLogs';

    protected static ?string $title = 'Histórico de atividades';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns(AuditLogResource::columns());
    }
}
