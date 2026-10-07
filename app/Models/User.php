<?php

namespace App\Models;

use App\Enums\Role;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

// El trait MustVerifyEmail lo hereda de Authenticatable: aquí solo se declara el
// contrato. Sin implements, el middleware 'verified' deja pasar a cualquiera.
class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, Notifiable;

    // role, subrole y active NO son asignables en masa: nadie se sube de rol desde un request.
    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'active' => 'boolean',
        ];
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    /** Ficha de un aspirante/aprendiz. El instructor usa fichas() (pivot N:M). */
    public function ficha(): BelongsTo
    {
        return $this->belongsTo(Ficha::class);
    }

    /** Fichas que acompaña un instructor. */
    public function fichas(): BelongsToMany
    {
        return $this->belongsToMany(Ficha::class, 'ficha_instructor', 'usuario_id', 'ficha_id');
    }

    /**
     * Ids de las fichas sobre las que este usuario tiene alcance: salvo el super
     * admin (todas) el resto ve únicamente lo suyo. Todo endpoint de negocio
     * debe filtrar por esta lista: es la regla de aislamiento entre fichas.
     *
     * @return array<int>
     */
    public function fichaIds(): array
    {
        if ($this->role === Role::SuperAdmin) {
            return Ficha::query()->pluck('id')->all();
        }

        return $this->fichas()->pluck('fichas.id')->all();
    }

    /** @return string[] */
    public function permissions(): array
    {
        $cfg = config('permissions');

        return match ($this->role) {
            Role::SuperAdmin => $cfg['all'],
            Role::Instructor => array_values(array_diff($cfg['all'], $cfg['super_admin_only'])),
            Role::Aspirante => $cfg['aspirante'],
            Role::Aprendiz => $cfg['aprendiz'][$this->subrole] ?? [],
            default => [],
        };
    }
}
