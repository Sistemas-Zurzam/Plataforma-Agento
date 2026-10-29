<?php

namespace App\Modules\Reclutamiento\Http\Controllers\Publico;

use App\Http\Controllers\Controller;
use App\Modules\Reclutamiento\Application\AccesoCandidatoService;
use App\Modules\Reclutamiento\Application\AplicacionPruebaService;
use App\Modules\Reclutamiento\Application\PortalEmpleosService;
use App\Modules\Reclutamiento\Application\PostulacionService;
use App\Modules\Reclutamiento\Http\Resources\Publico\MiPostulacionResource;
use App\Modules\Reclutamiento\Models\PruebaAsignacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Acceso del candidato: verificación de correo y consulta de SUS
 * postulaciones. Las respuestas a solicitar código son idénticas exista o no
 * el correo.
 */
class CandidatoController extends Controller
{
    public function __construct(
        private readonly AccesoCandidatoService $accesos,
        private readonly PortalEmpleosService $portal,
        private readonly PostulacionService $postulaciones,
        private readonly AplicacionPruebaService $pruebas,
    ) {}

    public function solicitarCodigo(Request $request): JsonResponse
    {
        $datos = $request->validate(['email' => ['required', 'email', 'max:191']]);
        $this->accesos->solicitarCodigo($datos['email'], $request->ip());

        return response()->json(['message' => AccesoCandidatoService::MENSAJE_GENERICO]);
    }

    public function verificarCodigo(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'email' => ['required', 'email', 'max:191'],
            'codigo' => ['required', 'digits:6'],
        ]);

        return response()->json(['data' => $this->accesos->verificarCodigo($datos['email'], $datos['codigo'])]);
    }

    public function cerrarSesion(Request $request): JsonResponse
    {
        $this->accesos->cerrarSesion($request->attributes->get('acceso_candidato'));

        return response()->json(['message' => 'Sesión cerrada.']);
    }

    public function perfil(Request $request): JsonResponse
    {
        $acceso = $request->attributes->get('acceso_candidato');
        $candidato = $this->portal->perfilPrevio($acceso);

        return response()->json(['data' => [
            'email' => $acceso->email,
            'perfil' => $candidato ? [
                'tipo_documento' => $candidato->tipo_documento,
                'numero_documento' => $candidato->numero_documento,
                'nombres' => $candidato->nombres,
                'apellido_paterno' => $candidato->apellido_paterno,
                'apellido_materno' => $candidato->apellido_materno,
                'celular' => $candidato->celular,
                'ubicacion' => $candidato->ubicacion,
                'resumen' => $candidato->resumen,
                'experiencias' => $candidato->antecedentes->where('tipo', 'experiencia')->map->only(['titulo', 'institucion', 'fecha_inicio', 'fecha_fin', 'descripcion'])->values(),
                'estudios' => $candidato->antecedentes->where('tipo', 'estudio')->map->only(['titulo', 'institucion', 'fecha_inicio', 'fecha_fin', 'descripcion'])->values(),
                'habilidades' => $candidato->habilidades->map->only(['nombre', 'nivel'])->values(),
            ] : null,
        ]]);
    }

    public function misPostulaciones(Request $request): AnonymousResourceCollection
    {
        return MiPostulacionResource::collection($this->portal->misPostulaciones($request->attributes->get('acceso_candidato')));
    }

    public function retirar(Request $request, int $postulacion): MiPostulacionResource
    {
        $propia = $this->portal->postulacionPropia($request->attributes->get('acceso_candidato'), $postulacion);
        $request->validate(['motivo' => ['nullable', 'string', 'max:500']]);
        $actualizada = $this->postulaciones->retirar($propia, $request->input('motivo') ?: 'Retiro solicitado por el candidato', null);

        return new MiPostulacionResource($actualizada->load('proceso.empresa'));
    }

    /**
     * Enlace nuevo a una prueba pendiente propia (invalida el anterior).
     */
    public function accesoPrueba(Request $request, int $asignacion): JsonResponse
    {
        $acceso = $request->attributes->get('acceso_candidato');
        $registro = PruebaAsignacion::whereKey($asignacion)
            ->whereHas('postulacion.candidato', fn ($q) => $q->where('email', $acceso->email))
            ->first();

        if (! $registro) {
            throw new NotFoundHttpException;
        }

        return response()->json(['data' => ['token' => $this->pruebas->regenerarToken($registro)]]);
    }
}
