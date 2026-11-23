<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Enums\PostulacionEstado;
use App\Enums\Role;
use App\Http\Requests\ConvocatoriaRequest;
use App\Models\Convocatoria;
use App\Models\ConvocatoriaSkill;
use App\Models\Ficha;
use App\Models\Parametro;
use App\Models\User;

trait PresentaConvocatoria
{
    private function presentarResumen(Convocatoria $c, User $user): array
    {
        return [
            'id' => $c->id,
            'codigo' => $this->codigoDe($c, $user),
            'nombre' => $c->nombre,
            'cargo' => $c->cargo,
            'area' => $c->area,
            'estado' => $this->parametro($c->estado),
            'ficha' => $this->fichaVisible($c, $user),
            'tipo_convocatoria' => $this->parametro($c->tipoConvocatoria),
            'tipo_empleo' => $this->parametro($c->tipoEmpleo),
            'modalidad' => $this->parametro($c->modalidad),
            'tipo_contrato' => $this->parametro($c->tipoContrato),
            'vacantes' => (int) $c->vacantes,
            'salario_min' => $this->numero($c->salario_min),
            'salario_max' => $this->numero($c->salario_max),
            'fecha_apertura' => $c->fecha_apertura?->toDateString(),
            'fecha_cierre' => $c->fecha_cierre?->toDateString(),
            'documentos' => $this->presentarDocumentos($c),
            'skills' => $c->skills->pluck('nombre')->values(),
            'skills_detalle' => $this->presentarSkills($c),
        ] + $this->camposDePostulacion($c, $user);
    }

    private function presentarDetalle(Convocatoria $c, User $user): array
    {
        return [
            'id' => $c->id,
            'codigo' => $this->codigoDe($c, $user),
            'ficha' => $this->fichaVisible($c, $user),
            'nombre' => $c->nombre,
            'cargo' => $c->cargo,
            'area' => $c->area,
            'descripcion' => $c->descripcion,
            'estado' => $this->parametro($c->estado),
            'vacantes' => (int) $c->vacantes,
            'salario_min' => $this->numero($c->salario_min),
            'salario_max' => $this->numero($c->salario_max),
            'tipo_convocatoria' => $this->parametro($c->tipoConvocatoria),
            'tipo_empleo' => $this->parametro($c->tipoEmpleo),
            'tipo_contrato' => $this->parametro($c->tipoContrato),
            'modalidad' => $this->parametro($c->modalidad),
            'funciones' => $c->funciones,
            'responsabilidades' => $c->responsabilidades,
            'perfil_requerido' => $c->perfil_requerido,
            'formacion' => $c->formacion,
            'experiencia' => $c->experiencia,
            'competencias' => $c->competencias,
            'conocimientos_especificos' => $c->conocimientos_especificos,
            'fecha_apertura' => $c->fecha_apertura?->toDateString(),
            'fecha_cierre' => $c->fecha_cierre?->toDateString(),
            'fecha_evaluacion' => $c->fecha_evaluacion?->toDateString(),
            // Solo para quien gestiona: el formulario bloquea la etapa de documentos.
            'tiene_postulaciones' => $user->can('convocatorias:gestionar') && $c->postulaciones()->exists(),
            'skills' => $c->skills->pluck('nombre')->values(),
            'skills_detalle' => $this->presentarSkills($c),
            'documentos' => $this->presentarDocumentos($c),
        ] + $this->camposDePostulacion($c, $user);
    }

    /**
     * Skills de la convocatoria con su origen: 'parametro_id' apunta al
     * parámetro del catálogo (tema skill_catalogo) y es NULL cuando la skill
     * es texto libre. La lista 'skills' (solo nombres) se mantiene por
     * compatibilidad con lo que ya consume el frontend.
     */
    private function presentarSkills(Convocatoria $c): array
    {
        return $c->skills
            ->map(fn (ConvocatoriaSkill $s) => [
                'id' => (int) $s->id,
                'nombre' => $s->nombre,
                'parametro_id' => $s->parametro_id === null ? null : (int) $s->parametro_id,
            ])
            ->values()
            ->all();
    }

    private function presentarDocumentos(Convocatoria $c): array
    {
        $documentos = $c->documentos;

        if ($documentos->isEmpty()) {
            return [];
        }

        // Asegurar orden estable e igual al criterio de negocio: obligatorios primero
        $ordenados = $documentos->sortByDesc(function ($d) {
            return (bool) ($d->pivot->obligatorio ?? false);
        })->values();

        return $ordenados->map(fn (Parametro $d) => [
            'id' => $d->id,
            'codigo' => $d->codigo,
            'nombre' => $d->nombre,
            // Obligatoriedad real guardada: todo documento incluido en la
            // convocatoria queda obligatorio y pesa en la evaluación.
            'obligatorio' => (bool) $d->pivot->obligatorio,
            // Peso (%) de este documento en la evaluación de esta convocatoria.
            // null = sin configurar (reparto equitativo en la evaluación).
            'ponderacion' => $d->pivot->ponderacion === null
                ? null
                : round((float) $d->pivot->ponderacion, 2),
        ])->values()->all();
    }

    /**
     * Código de la convocatoria (CONV-AAAA-NNN): es un dato de GESTIÓN, así
     * que solo lo recibe quien está en la lista blanca (instructor, super
     * admin y el subrol gestor_convocatorias). Para el resto llega en null:
     * la clave sigue existiendo para no romper el contrato del JSON.
     */
    private function codigoDe(Convocatoria $c, User $user): ?string
    {
        return $this->veIdentificadorConvocatoria($user) ? $c->codigo : null;
    }

    private function ficha(Convocatoria $c): ?array
    {
        return $c->ficha
            ? [
                'id' => $c->ficha->id,
                'codigo' => $c->ficha->codigo,
                'nombre_programa' => $c->ficha->nombre_programa,
            ]
            : null;
    }

    /**
     * Ficha de la convocatoria: SOLO la ven el instructor y el super admin.
     * El aspirante y el subrol gestor_convocatorias reciben null (la clave
     * sigue en la respuesta, para no romper el contrato del JSON).
     */
    private function fichaVisible(Convocatoria $c, User $user): ?array
    {
        if (! in_array($user->role, [Role::Instructor, Role::SuperAdmin], true)) {
            return null;
        }

        return $this->ficha($c);
    }

    private function parametro(?Parametro $parametro): ?array
    {
        return $parametro
            ? ['id' => $parametro->id, 'codigo' => $parametro->codigo, 'nombre' => $parametro->nombre]
            : null;
    }

    private function numero(mixed $valor): ?float
    {
        return $valor === null ? null : round((float) $valor, 2);
    }

    /**
     * Claves de postulación que le corresponden al usuario (o ninguna):
     *  - gestor -> ['postulantes' => int]
     *  - aspirante -> ['mi_postulacion' => null | ['id', 'estado' => ['codigo','nombre']]]
     *  - otro -> []
     * Nunca aparecen las dos.
     */
    private function camposDePostulacion(Convocatoria $c, User $user): array
    {
        if ($user->can('convocatorias:gestionar')) {
            return ['postulantes' => (int) ($c->postulantes_count ?? 0)];
        }

        if ($user->role === Role::Aspirante && $c->relationLoaded('postulaciones')) {
            $propia = $c->postulaciones->first();

            return [
                'mi_postulacion' => $propia === null ? null : [
                    'id' => $propia->id,
                    'estado' => [
                        'codigo' => $propia->estado?->codigo,
                        'nombre' => $propia->estado?->nombre,
                    ],
                ],
            ];
        }

        return [];
    }

    /** Igual que aplicarCamposDePostulacion, para un recurso ya cargado (+1 consulta). */
    private function cargarCamposDePostulacion(Convocatoria $convocatoria, User $user): void
    {
        if ($user->can('convocatorias:gestionar')) {
            $estadoPostulada = $this->parametroId('estado_postulacion', PostulacionEstado::Postulada->value);

            $convocatoria->loadCount([
                'postulaciones as postulantes_count' => fn ($sub) => $sub
                    ->where('estado_id', $estadoPostulada)
                    ->whereHas('user', fn ($u) => $u->where('active', true)),
            ]);

            return;
        }

        if ($user->role === Role::Aspirante) {
            $convocatoria->load([
                'postulaciones' => fn ($sub) => $sub->where('user_id', $user->id)->with('estado'),
            ]);
        }
    }

    /** @return array<string, string> */
    private function mensajes(): array
    {
        return ConvocatoriaRequest::mensajes();
    }
}
