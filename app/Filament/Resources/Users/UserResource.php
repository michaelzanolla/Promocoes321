<?php

namespace App\Filament\Resources\Users;

use App\Enums\Role;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\RelationManagers\AuditLogsRelationManager;
use App\Models\AuditLog;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'usuário';

    protected static ?string $pluralModelLabel = 'usuários';

    protected static ?string $slug = 'usuarios';

    protected static ?int $navigationSort = 2;

    /** O administrador não pode rebaixar nem desativar a própria conta (evita ficar sem acesso). */
    public static function isSelf(?User $record): bool
    {
        return $record !== null && $record->is(auth()->user());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Dados cadastrais')->columns(2)->components([
                TextInput::make('name')->label('Nome')->required()->maxLength(255),
                TextInput::make('email')->label('E-mail')->email()->required()->maxLength(255)->unique(ignoreRecord: true),
                TextInput::make('phone')->label('Telefone')->tel()->maxLength(20),
            ]),
            Section::make('Acesso')->columns(2)
                ->description('Perfil e situação só podem ser alterados aqui, por um administrador.')
                ->components([
                    Select::make('role')->label('Perfil')->required()->selectablePlaceholder(false)
                        ->options(collect(Role::cases())->mapWithKeys(fn (Role $r) => [$r->value => $r->label()])->all())
                        ->default(Role::Participant->value)
                        ->disabled(fn (?User $record) => self::isSelf($record)),
                    Toggle::make('is_active')->label('Conta ativa')->default(true)->inline(false)
                        ->disabled(fn (?User $record) => self::isSelf($record)),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->label('Nome')->searchable()->description(fn (User $record) => $record->email),
                TextColumn::make('email')->label('E-mail')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('role')->label('Perfil')->badge()
                    ->formatStateUsing(fn (Role $state) => $state->label())
                    ->color(fn (Role $state) => $state === Role::Admin ? 'primary' : 'gray'),
                IconColumn::make('is_active')->label('Ativa')->boolean(),
                IconColumn::make('email_verified_at')->label('E-mail confirmado')->boolean()
                    ->state(fn (User $record) => $record->email_verified_at !== null),
                TextColumn::make('created_at')->label('Cadastro')->dateTime('d/m/Y')->sortable(),
            ])
            ->filters([
                SelectFilter::make('role')->label('Perfil')
                    ->options(collect(Role::cases())->mapWithKeys(fn (Role $r) => [$r->value => $r->label()])->all()),
                TernaryFilter::make('is_active')->label('Conta ativa'),
            ])
            ->recordActions([
                EditAction::make(),
                self::toggleActiveAction(),
            ]);
    }

    public static function toggleActiveAction(): Action
    {
        return Action::make('toggleActive')
            ->label(fn (User $record) => $record->is_active ? 'Desativar' : 'Ativar')
            ->icon(fn (User $record) => $record->is_active ? Heroicon::OutlinedNoSymbol : Heroicon::OutlinedCheckCircle)
            ->color(fn (User $record) => $record->is_active ? 'danger' : 'success')
            ->hidden(fn (User $record) => self::isSelf($record))
            ->requiresConfirmation()
            ->modalDescription(fn (User $record) => $record->is_active
                ? 'A pessoa não conseguirá mais entrar e, se estiver logada, será desconectada.'
                : 'A pessoa voltará a conseguir entrar.')
            ->action(function (User $record) {
                $record->forceFill(['is_active' => ! $record->is_active])->save();
                AuditLog::record($record->is_active ? 'user.activated' : 'user.deactivated', $record);
                Notification::make()->success()->title($record->is_active ? 'Conta ativada.' : 'Conta desativada.')->send();
            });
    }

    public static function getRelations(): array
    {
        return [
            AuditLogsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/novo'),
            'edit' => EditUser::route('/{record}/editar'),
        ];
    }
}
