<?php

namespace App\Tests\Feature;

use App\Enums\Role;
use App\Models\Ficha;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FichaIdsTest extends TestCase
{
    use RefreshDatabase;

    private function ficha(string $codigo): Ficha
    {
        return Ficha::create(['codigo' => $codigo, 'nombre_programa' => 'Análisis y Desarrollo de Software']);
    }

    private function usuario(Role $role, ?string $subrole = null, array $extra = []): User
    {
        return User::factory()->create($extra + [
            'role' => $role,
            'subrole' => $subrole,
            'active' => true,
            'email_verified_at' => now(),
        ]);
    }

    public function test_aspirante_con_ficha_ve_su_ficha(): void
    {
        $ficha = $this->ficha('3173334');
        $aspirante = $this->usuario(Role::Aspirante, null, ['ficha_id' => $ficha->id]);

        $this->assertSame([$ficha->id], $aspirante->fichaIds());
    }

    public function test_aspirante_sin_ficha_no_ve_fichas(): void
    {
        $aspirante = $this->usuario(Role::Aspirante);

        $this->assertSame([], $aspirante->fichaIds());
    }

    public function test_aprendiz_con_ficha_ve_su_ficha(): void
    {
        $ficha = $this->ficha('3173334');
        $aprendiz = $this->usuario(Role::Aprendiz, 'general', ['ficha_id' => $ficha->id]);

        $this->assertSame([$ficha->id], $aprendiz->fichaIds());
    }

    public function test_el_ficha_ids_llega_al_me_del_aspirante(): void
    {
        $ficha = $this->ficha('3173334');
        $aspirante = $this->usuario(Role::Aspirante, null, ['ficha_id' => $ficha->id]);

        $this->actingAs($aspirante)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.ficha_ids', [$ficha->id]);
    }

    public function test_el_ficha_ids_llega_al_me_del_aprendiz(): void
    {
        $ficha = $this->ficha('3173334');
        $aprendiz = $this->usuario(Role::Aprendiz, 'general', ['ficha_id' => $ficha->id]);

        $this->actingAs($aprendiz)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.ficha_ids', [$ficha->id]);
    }

    public function test_el_me_del_instructor_mantiene_solo_las_fichas_del_pivot(): void
    {
        $fichaMia = $this->ficha('2896411');
        $this->ficha('2631620');

        $instructor = $this->usuario(Role::Instructor);
        $instructor->fichas()->attach($fichaMia->id);

        $this->actingAs($instructor)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.ficha_ids', [$fichaMia->id]);
    }

    public function test_el_me_del_super_admin_mantiene_todas_las_fichas(): void
    {
        $this->ficha('3173334');
        $this->ficha('2896411');
        $this->ficha('2631620');

        $admin = $this->usuario(Role::SuperAdmin);

        $this->actingAs($admin)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.ficha_ids', Ficha::query()->pluck('id')->all());
    }

    public function test_instructor_ve_solo_sus_fichas_del_pivot(): void
    {
        $fichaMia = $this->ficha('2896411');
        $fichaAjena = $this->ficha('2631620');

        $instructor = $this->usuario(Role::Instructor);
        $instructor->fichas()->attach($fichaMia->id);

        // El instructor no lleva ficha_id: su único alcance es el pivot.
        $this->assertSame([$fichaMia->id], $instructor->fichaIds());
        $this->assertNotContains($fichaAjena->id, $instructor->fichaIds());
    }

    public function test_super_admin_ve_todas_las_fichas(): void
    {
        $this->ficha('3173334');
        $this->ficha('2896411');
        $this->ficha('2631620');

        $admin = $this->usuario(Role::SuperAdmin);

        $this->assertSame(Ficha::query()->pluck('id')->all(), $admin->fichaIds());
    }
}