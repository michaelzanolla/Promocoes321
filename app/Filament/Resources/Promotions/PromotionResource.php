<?php

namespace App\Filament\Resources\Promotions;

use App\Enums\PaymentMethod;
use App\Enums\PromotionPhase;
use App\Enums\PromotionStatus;
use App\Filament\Resources\Promotions\Pages\CreatePromotion;
use App\Filament\Resources\Promotions\Pages\EditPromotion;
use App\Filament\Resources\Promotions\Pages\ListPromotions;
use App\Filament\Resources\Promotions\Pages\ViewPromotion;
use App\Models\Promotion;
use App\Support\Format;
use BackedEnum;
use Closure;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class PromotionResource extends Resource
{
    protected static ?string $model = Promotion::class;

    /** Sem SVG: um SVG enviado pode conter script e é servido pelo próprio site. */
    public const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public const MAX_NUMBERS = 100000;

    /** A faixa do site precisa estar dentro da numeração da ação. */
    private static function insideSeries(Get $get): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($get) {
            if ((int) $value < (int) $get('series_start') || (int) $value > (int) $get('series_end')) {
                $fail('A faixa do site precisa estar dentro da numeração da ação ('.$get('series_start').' a '.$get('series_end').').');
            }
        };
    }

    /**
     * Tela → banco: a faixa do site vira number_start/total_numbers (usados pela grade e pela reserva).
     * Campos travados (promoção com pedidos) não chegam aqui e ficam como estão.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function numberingToModel(array $data): array
    {
        $scope = $data['online_scope'] ?? 'all';
        $onlineEnd = $data['online_end'] ?? null;
        unset($data['online_scope'], $data['online_end']);

        if (! array_key_exists('series_start', $data)) {
            return $data;
        }

        [$from, $to] = $scope === 'range'
            ? [(int) $data['number_start'], (int) $onlineEnd]
            : [(int) $data['series_start'], (int) $data['series_end']];

        return [...$data, 'number_start' => $from, 'total_numbers' => $to - $from + 1];
    }

    /**
     * Banco → tela.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function numberingToForm(array $data): array
    {
        $last = (int) $data['number_start'] + (int) $data['total_numbers'] - 1;
        $data['series_start'] ??= $data['number_start'];
        $data['series_end'] ??= $last;
        $data['online_end'] = $last;
        $data['online_scope'] = (int) $data['series_start'] === (int) $data['number_start'] && (int) $data['series_end'] === $last ? 'all' : 'range';

        return $data;
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?string $modelLabel = 'promoção';

    protected static ?string $pluralModelLabel = 'promoções';

    protected static ?string $slug = 'promocoes';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        // Depois do primeiro pedido estes campos ficam travados (a regra também vale no model).
        $locked = fn (?Promotion $record): bool => (bool) $record?->hasOrders();

        return $schema->columns(1)->components([
            Section::make('Conteúdo')->columns(2)->components([
                TextInput::make('title')->label('Nome')->required()->maxLength(255)->columnSpanFull()
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (Set $set, ?string $state, ?Promotion $record) {
                        if ($record === null) {
                            $set('slug', Str::slug((string) $state));
                        }
                    }),
                TextInput::make('slug')->label('Endereço público')->prefix('/promocoes/')->required()->maxLength(255)
                    ->alphaDash()->unique(ignoreRecord: true)
                    ->disabled(fn (?Promotion $record) => $record !== null && $record->status !== PromotionStatus::Draft)
                    ->helperText('Não muda depois da publicação, para não quebrar links já compartilhados.'),
                TextInput::make('category')->label('Categoria')->maxLength(60)->datalist(fn () => Promotion::query()->whereNotNull('category')->distinct()->pluck('category')->all()),
                TextInput::make('prize')->label('Prêmio ou benefício')->maxLength(255)->columnSpanFull(),
                Textarea::make('summary')->label('Resumo')->helperText('Aparece no card da página inicial.')->maxLength(300)->rows(2)->columnSpanFull(),
                MarkdownEditor::make('description')->label('Descrição completa')->columnSpanFull()
                    ->toolbarButtons([['bold', 'italic', 'link'], ['heading', 'bulletList', 'orderedList'], ['undo', 'redo']]),
                FileUpload::make('image_path')->label('Imagem principal')->image()->acceptedFileTypes(self::IMAGE_TYPES)->disk('public')->directory('promocoes')
                    ->maxSize(2048)->helperText('JPG, PNG ou WebP, até 2 MB. Proporção ideal 16:10.')->columnSpanFull(),
                Repeater::make('images')->label('Galeria (opcional)')->relationship()->orderColumn('position')
                    ->schema([
                        FileUpload::make('path')->label('Imagem')->image()->acceptedFileTypes(self::IMAGE_TYPES)->disk('public')->directory('promocoes')->maxSize(2048)->required(),
                        TextInput::make('alt')->label('Descrição da imagem (acessibilidade)')->maxLength(255),
                    ])->columns(2)->defaultItems(0)->addActionLabel('Adicionar imagem')->collapsible()->columnSpanFull(),
            ]),

            // Um aviso só para a seção: avisos por campo quebravam o alinhamento dos rótulos.
            Section::make('Números e valores')->columns(3)
                ->description(fn (?Promotion $record) => $locked($record) ? 'Preço, numeração e formas de pagamento estão travados porque a promoção já tem pedidos.' : null)
                ->components([
                    TextInput::make('unit_price_cents')->label('Preço por número')->prefix('R$')->required()
                        ->numeric()->minValue(0.01)->step(0.01)
                        ->formatStateUsing(fn ($state) => $state === null ? null : number_format($state / 100, 2, '.', ''))
                        ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100))
                        ->disabled($locked),
                    // Numeração da ação inteira e a parte vendida no site (ver numberingToModel()).
                    TextInput::make('series_start')->label('Primeiro número da ação')->required()->integer()->minValue(0)->default(1)
                        ->helperText('Ex.: 1 (de 0001 a 1000) ou 0 (de 000 a 999).')
                        ->live(onBlur: true)->disabled($locked),
                    TextInput::make('series_end')->label('Último número da ação')->required()->integer()->gte('series_start')
                        ->rule(fn (Get $get) => function (string $attribute, mixed $value, Closure $fail) use ($get) {
                            if ((int) $value - (int) $get('series_start') + 1 > self::MAX_NUMBERS) {
                                $fail('A ação pode ter no máximo '.number_format(self::MAX_NUMBERS, 0, ',', '.').' números.');
                            }
                        })
                        ->live(onBlur: true)->disabled($locked),
                    Radio::make('online_scope')->label('Vender no site')->default('all')->live()->disabled($locked)
                        ->options(['all' => 'Todos os números da ação', 'range' => 'Só uma faixa (o restante é vendido fora do site)']),
                    TextInput::make('number_start')->label('Faixa do site: de')->integer()
                        ->visible(fn (Get $get) => $get('online_scope') === 'range')->required()
                        ->rule(fn (Get $get) => self::insideSeries($get))->disabled($locked),
                    TextInput::make('online_end')->label('Faixa do site: até')->integer()->gte('number_start')
                        ->visible(fn (Get $get) => $get('online_scope') === 'range')->required()
                        ->rule(fn (Get $get) => self::insideSeries($get))->disabled($locked),
                    TextInput::make('min_per_order')->label('Mínimo por pedido')->integer()->minValue(1)->default(1)->required(),
                    TextInput::make('max_per_order')->label('Máximo por pedido')->integer()->minValue(1)->helperText('Vazio = sem limite.')
                        ->gte('min_per_order'),
                    TextInput::make('reservation_minutes')->label('Prazo da reserva (minutos)')->integer()->minValue(5)->maxValue(1440)->default(15)->required()
                        ->helperText('Tempo para pagar antes de os números voltarem a ficar livres.'),
                    CheckboxList::make('payment_methods')->label('Formas de pagamento')
                        ->options(collect(PaymentMethod::cases())->mapWithKeys(fn (PaymentMethod $m) => [$m->value => $m->label()])->all())
                        ->default([PaymentMethod::Pix->value])->required()->minItems(1)
                        ->disabled($locked),
                    Toggle::make('allow_random')->label('Permitir números aleatórios')->default(true)->inline(false),
                ]),

            Section::make('Datas')->columns(2)->components([
                DateTimePicker::make('starts_at')->label('Início')->seconds(false)->native(false)->displayFormat('d/m/Y H:i'),
                DateTimePicker::make('ends_at')->label('Encerramento')->seconds(false)->native(false)->displayFormat('d/m/Y H:i')
                    ->rule(fn (Get $get) => function (string $attribute, mixed $value, Closure $fail) use ($get) {
                        if ($value && $get('starts_at') && strtotime((string) $value) <= strtotime((string) $get('starts_at'))) {
                            $fail('O encerramento precisa ser depois do início.');
                        }
                    }),
            ]),

            Section::make('Regras e informações legais')->components([
                MarkdownEditor::make('rules')->label('Regras de participação')
                    ->toolbarButtons([['bold', 'italic', 'link'], ['bulletList', 'orderedList']]),
                MarkdownEditor::make('draw_info')->label('Como o resultado é apurado')
                    ->helperText('Ex.: data e horário do sorteio, como ele será feito (ao vivo em rede social, presencial com testemunhas etc.) e onde o resultado será divulgado.')
                    ->toolbarButtons([['bold', 'italic', 'link'], ['bulletList', 'orderedList']]),
                TextInput::make('legal_reference')->label('Referência legal')->maxLength(255)
                    ->helperText('Número de autorização ou parecer jurídico. Obrigatório para publicar em produção.'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withNumberCounts())
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('title')->label('Promoção')->searchable()->wrap()
                    ->description(fn (Promotion $record) => $record->is_demo ? 'Fictícia (demonstração)' : $record->category),
                TextColumn::make('phase')->label('Situação')->badge()
                    ->state(fn (Promotion $record) => $record->phase()->label())
                    ->color(fn (Promotion $record) => match ($record->phase()) {
                        PromotionPhase::Open => 'success',
                        PromotionPhase::Scheduled, PromotionPhase::Paused => 'warning',
                        PromotionPhase::Cancelled => 'danger',
                        PromotionPhase::Drawn => 'primary',
                        default => 'gray',
                    }),
                TextColumn::make('unit_price_cents')->label('Preço')->sortable()
                    ->formatStateUsing(fn (int $state) => Format::brl($state)),
                TextColumn::make('sold_count')->label('Vendidos')
                    ->state(fn (Promotion $record) => "{$record->sold_count} / {$record->total_numbers}")
                    ->description(fn (Promotion $record) => $record->reserved_count ? "{$record->reserved_count} reservados" : null),
                TextColumn::make('ends_at')->label('Encerramento')->dateTime('d/m/Y H:i')->sortable()->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('phase')->label('Situação')
                    ->options(collect(PromotionPhase::cases())->mapWithKeys(fn (PromotionPhase $p) => [$p->value => $p->label()])->all())
                    ->query(fn ($query, array $data) => filled($data['value'] ?? null)
                        ? $query->inPhase(PromotionPhase::from($data['value']))
                        : $query),
                SelectFilter::make('category')->label('Categoria')
                    ->options(fn () => Promotion::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category', 'category')->all()),
            ])
            ->recordActions([
                EditAction::make(),
                // Congeladas (resultado publicado ou canceladas) abrem só para leitura.
                ViewAction::make()->hidden(fn (Promotion $record) => auth()->user()?->can('update', $record) ?? false),
                ActionGroup::make(PromotionActions::all()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPromotions::route('/'),
            'create' => CreatePromotion::route('/nova'),
            'edit' => EditPromotion::route('/{record}/editar'),
            'view' => ViewPromotion::route('/{record}'),
        ];
    }
}
