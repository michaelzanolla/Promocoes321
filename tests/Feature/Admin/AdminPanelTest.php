<?php

namespace Tests\Feature\Admin;

use App\Enums\PromotionStatus;
use App\Enums\Role;
use App\Filament\Pages\ManageSettings;
use App\Filament\Resources\Promotions\Pages\CreatePromotion;
use App\Filament\Resources\Promotions\Pages\EditPromotion;
use App\Filament\Resources\Promotions\Pages\ListPromotions;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Promotion;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
    }

    public function test_guests_go_to_the_site_login(): void
    {
        $this->get('/admin')->assertRedirect(route('login'));
    }

    public function test_participants_and_inactive_admins_are_blocked(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin')->assertForbidden();

        $inactiveAdmin = User::factory()->admin()->inactive()->create();
        $this->actingAs($inactiveAdmin)->get('/admin')->assertForbidden();
    }

    public function test_admin_pages_render(): void
    {
        $this->actingAs($this->admin);
        $promotion = Promotion::factory()->open()->create();

        foreach (['/admin', '/admin/promocoes', '/admin/promocoes/nova', "/admin/promocoes/{$promotion->id}/editar",
            '/admin/usuarios', '/admin/usuarios/novo', '/admin/auditoria', '/admin/configuracoes'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_create_promotion_converts_price_and_starts_as_draft(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreatePromotion::class)
            ->fillForm([
                'title' => 'Bicicleta aro 29',
                'slug' => 'bicicleta-aro-29',
                'unit_price_cents' => '12.50',
                'total_numbers' => 500,
                'number_start' => 1,
                'min_per_order' => 1,
                'reservation_minutes' => 15,
                'payment_methods' => ['pix'],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $promotion = Promotion::where('slug', 'bicicleta-aro-29')->firstOrFail();
        $this->assertSame(1250, $promotion->unit_price_cents);
        $this->assertSame(PromotionStatus::Draft, $promotion->status);
        $this->assertSame($this->admin->id, $promotion->created_by);
        $this->assertDatabaseHas('audit_logs', ['action' => 'promotion.created', 'subject_id' => $promotion->id]);
    }

    public function test_promotion_image_rejects_svg(): void
    {
        Storage::fake('public');
        $this->actingAs($this->admin);

        Livewire::test(CreatePromotion::class)
            ->fillForm(['image_path' => UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')])
            ->call('create')
            ->assertHasFormErrors(['image_path']);
    }

    public function test_locked_fields_are_disabled_once_there_are_orders(): void
    {
        $this->actingAs($this->admin);
        $promotion = Promotion::factory()->open()->create(['unit_price_cents' => 500]);
        Order::factory()->create(['promotion_id' => $promotion->id]);

        Livewire::test(EditPromotion::class, ['record' => $promotion->getRouteKey()])
            ->assertFormFieldDisabled('unit_price_cents')
            ->assertFormFieldDisabled('total_numbers')
            ->fillForm(['title' => 'Novo título'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Novo título', $promotion->fresh()->title);
        $this->assertSame(500, $promotion->fresh()->unit_price_cents);
        $this->assertDatabaseHas('audit_logs', ['action' => 'promotion.updated', 'subject_id' => $promotion->id]);
    }

    public function test_publish_action_reports_missing_requirements(): void
    {
        $this->actingAs($this->admin);
        $draft = Promotion::factory()->create();

        Livewire::test(ListPromotions::class)
            ->callTableAction('publish', $draft)
            ->assertNotified('Não foi possível concluir');

        $this->assertSame(PromotionStatus::Draft, $draft->fresh()->status);
    }

    public function test_admin_created_user_gets_reset_link_and_chosen_role(): void
    {
        Notification::fake();
        $this->actingAs($this->admin);

        Livewire::test(CreateUser::class)
            ->fillForm(['name' => 'Operadora', 'email' => 'operadora@example.com', 'role' => 'admin', 'is_active' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::where('email', 'operadora@example.com')->firstOrFail();
        $this->assertSame(Role::Admin, $user->role);
        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_admin_can_not_demote_or_deactivate_themself(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(EditUser::class, ['record' => $this->admin->getRouteKey()])
            ->assertFormFieldDisabled('role')
            ->assertFormFieldDisabled('is_active')
            ->fillForm(['name' => 'Nome novo'])
            ->call('save');

        $this->admin->refresh();
        $this->assertSame('Nome novo', $this->admin->name);
        $this->assertTrue($this->admin->isAdmin());
        $this->assertTrue($this->admin->is_active);
    }

    public function test_admin_can_deactivate_another_user(): void
    {
        $this->actingAs($this->admin);
        $participant = User::factory()->create();

        Livewire::test(ListUsers::class)->callTableAction('toggleActive', $participant);

        $this->assertFalse($participant->fresh()->is_active);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.deactivated', 'subject_id' => $participant->id]);
    }

    public function test_settings_are_saved_and_shown_on_the_site(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ManageSettings::class)
            ->fillForm(['organizer_name' => 'Associação Amigos do Bairro', 'hero_title' => 'Participe já.'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Associação Amigos do Bairro', Setting::read('organizer_name'));
        $this->assertSame(1, AuditLog::where('action', 'settings.updated')->count());

        $this->get(route('home'))->assertSee('Associação Amigos do Bairro')->assertSee('Participe já.');
    }

    public function test_admin_can_preview_a_draft_but_public_can_not(): void
    {
        $draft = Promotion::factory()->create(['title' => 'Rascunho em preparação']);

        $this->get(route('promotions.show', $draft->slug))->assertNotFound();

        $this->actingAs($this->admin)->get(route('promotions.show', $draft->slug))
            ->assertOk()
            ->assertSee('Pré-visualização');
    }
}
