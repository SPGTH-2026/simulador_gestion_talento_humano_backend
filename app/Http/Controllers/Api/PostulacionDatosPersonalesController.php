<?php

namespace App\Http\Controllers\Api;

use App\Enums\PostulacionEstado;
use App\Http\Controllers\Api\Concerns\GestionaPostulacionPropia;
use App\Http\Controllers\Controller;
use App\Http\Requests\DatosPersonalesRequest;
use App\Models\Postulacion;
use App\Services\DatosPersonalesDePostulacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Datos personales de una postulación (paso previo a los documentos). Solo los ve y edita su dueño; una postulación ajena
 * o inexistente responde 404.
 *
 * Se editan en borrador o retirada, con la convocatoria abierta. Una postulación ya enviada no se edita: para cambiar los
 * datos (por ejemplo la edad, que cambia los requisitos) la persona la retira y vuelve a postularse.
 */
class PostulacionDatosPersonalesController extends Controller
{
    use GestionaPostulacionPropia;

    public function __construct(private DatosPersonalesDePostulacion $datos) {}

    public function show(Request $request, Postulacion $postulacion): JsonResponse
    {
        $this->exigirPropia($request, $postulacion);

        return response()->json(['data' => $this->datos->presentar($postulacion)]);
    }

    public function update(DatosPersonalesRequest $request, Postulacion $postulacion): JsonResponse
    {
        $this->exigirPropia($request, $postulacion);
        $this->exigirEnviable($postulacion);
        $this->exigirEditable($postulacion, 'datos_personales');

        $this->datos->guardar($postulacion, $request->validated());

        return response()->json(['data' => $this->datos->presentar($postulacion->refresh())]);
    }

    /** Enviada la postulación, los datos quedan fijos: hay que retirarla para cambiarlos. */
    private function exigirEnviable(Postulacion $postulacion): void
    {
        $postulacion->loadMissing('estado');

        if ($postulacion->estado?->codigo === PostulacionEstado::Postulada->value) {
            throw ValidationException::withMessages([
                'datos_personales' => 'Tu postulación ya fue enviada. Para cambiar tus datos, retírala y vuelve a postularte.',
            ]);
        }
    }
}
