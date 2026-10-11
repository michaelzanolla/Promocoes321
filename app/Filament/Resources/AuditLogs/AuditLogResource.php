<?php

namespace App\Filament\Resources\AuditLogs;

use App\Filament\Resources\AuditLogs\Pages\ManageAuditLogs;
use App\Models\AuditLog;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Somente leitura: registros de auditoria não podem ser criados, editados nem apagados pelo painel.
 */
class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $modelLabel = 'registro de auditoria';

    protected static ?string $pluralModelLabel = 'auditoria';

    protected static ?string $slug = 'auditoria';

    protected static ?int $navigationSort = 3;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextEntry::make('created_at')->label('Quando')->dateTime('d/m/Y H:i:s'),
            TextEntry::make('user.name')->label('Quem')->placeholder('Sistema'),
            TextEntry::make('action')->label('Ação')->formatStateUsing(fn (AuditLog $record) => $record->actionLabel()),
            TextEntry::make('subject')->label('Registro')->state(fn (AuditLog $record) => self::subject($record)),
            TextEntry::make('ip')->label('IP')->placeholder('—'),
            KeyValueEntry::make('changes')->label('Detalhes')->columnSpanFull()
                ->state(fn (AuditLog $record) => collect($record->changes ?? [])
                    ->map(fn ($value) => is_scalar($value) || $value === null ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE))
                    ->all()),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns(self::columns())
            ->filters([
                SelectFilter::make('action')->label('Ação')->options(AuditLog::ACTION_LABELS),
                SelectFilter::make('user')->label('Quem')->relationship('user', 'name')->searchable(),
            ])
            ->recordActions([ViewAction::make()]);
    }

    /**
     * @return list<TextColumn>
     */
    public static function columns(): array
    {
        return [
            TextColumn::make('created_at')->label('Quando')->dateTime('d/m/Y H:i')->sortable(),
            TextColumn::make('user.name')->label('Quem')->placeholder('Sistema'),
            TextColumn::make('action')->label('Ação')->formatStateUsing(fn (AuditLog $record) => $record->actionLabel()),
            TextColumn::make('subject')->label('Registro')->state(fn (AuditLog $record) => self::subject($record)),
        ];
    }

    public static function subject(AuditLog $record): string
    {
        if ($record->subject_type === null) {
            return '—';
        }

        $type = ['App\Models\Promotion' => 'Promoção', 'App\Models\User' => 'Usuário', 'App\Models\Order' => 'Pedido'][$record->subject_type] ?? class_basename($record->subject_type);

        return "{$type} #{$record->subject_id}";
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAuditLogs::route('/'),
        ];
    }
}
