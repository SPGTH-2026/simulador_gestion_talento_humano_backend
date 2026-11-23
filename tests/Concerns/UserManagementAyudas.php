<?php

namespace Tests\Concerns;

use App\Enums\Role;
use App\Models\Ficha;
use App\Models\User;

trait UserManagementAyudas
{
    private function ficha(string $codigo, string $programa = 'Análisis y Desarrollo de Software'): Ficha
    {
        return Ficha::create(['codigo' => $codigo, 'nombre_programa' => $programa]);
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

    private function instructor(): User
    {
        $instructor = $this->usuario(Role::Instructor);
        $instructor->fichas()->attach($this->ficha('2896411')->id);

        return $instructor;
    }

    // ── Acceso ─────────────────────────────────────────────────────────

    /** @return array{0: User, 1: User, 2: Ficha, 3: Ficha} dos instructores, cada uno con su ficha */
    private function dosInstructores(): array
    {
        $fichaA = $this->ficha('1111111');
        $fichaB = $this->ficha('2222222');
        $a = $this->usuario(Role::Instructor);
        $b = $this->usuario(Role::Instructor);
        $a->fichas()->attach($fichaA->id);
        $b->fichas()->attach($fichaB->id);

        return [$a, $b, $fichaA, $fichaB];
    }
}
