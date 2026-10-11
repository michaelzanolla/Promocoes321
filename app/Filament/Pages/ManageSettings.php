<?php

namespace App\Filament\Pages;

use App\Models\AuditLog;
use App\Models\Setting;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Configurações exibidas no site: responsável, contato e textos da página inicial.
 *
 * @property-read Schema $form
 */
class ManageSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $navigationLabel = 'Configurações';

    protected static ?string $title = 'Configurações do site';

    protected static ?string $slug = 'configuracoes';

    protected static ?int $navigationSort = 9;

    private const KEYS = [
        'organizer_name', 'organizer_document', 'organizer_city', 'contact_email', 'contact_whatsapp',
        'hero_title', 'hero_highlight', 'hero_text',
    ];

    /** @var array<string, string|null> */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(collect(self::KEYS)->mapWithKeys(fn (string $key) => [$key => Setting::read($key)])->all());
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Responsável pelas promoções')
                ->description('Aparece no rodapé, na página de cada promoção e nos comprovantes. Preencha só com dados reais.')
                ->columns(2)
                ->components([
                    TextInput::make('organizer_name')->label('Nome ou razão social')->maxLength(255),
                    TextInput::make('organizer_document')->label('CPF ou CNPJ exibido')->maxLength(30)
                        ->helperText('Deixe vazio se a análise jurídica não exigir a exibição.'),
                    TextInput::make('organizer_city')->label('Cidade / UF')->maxLength(100),
                ]),
            Section::make('Contato e suporte')->columns(2)->components([
                TextInput::make('contact_email')->label('E-mail')->email()->maxLength(255),
                TextInput::make('contact_whatsapp')->label('WhatsApp')->tel()->maxLength(30)->placeholder('(11) 99999-9999'),
            ]),
            Section::make('Página inicial')->components([
                TextInput::make('hero_title')->label('Título — primeira linha')->required()->maxLength(80),
                TextInput::make('hero_highlight')->label('Título — linha em destaque')->required()->maxLength(80),
                Textarea::make('hero_text')->label('Texto de apoio')->required()->maxLength(400)->rows(3),
            ]),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([Action::make('save')->label('Salvar configurações')->submit('save')]),
                ]),
        ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $before = collect(self::KEYS)->mapWithKeys(fn (string $key) => [$key => Setting::read($key)]);

        Setting::write($data);

        $changed = collect($data)->filter(fn ($value, $key) => $value !== $before[$key]);
        if ($changed->isNotEmpty()) {
            AuditLog::record('settings.updated', null, $changed->all());
        }

        Notification::make()->success()->title('Configurações salvas.')->send();
    }
}
