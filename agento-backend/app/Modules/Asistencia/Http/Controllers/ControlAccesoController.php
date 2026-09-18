<?php

namespace App\Modules\Asistencia\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asistencia\Http\Requests\EscanearCarnetRequest;
use App\Modules\Asistencia\Http\Requests\RevocarCredencialCarnetRequest;
use App\Modules\Asistencia\Services\CarnetCredentialService;
use App\Modules\Asistencia\Services\RegistrarMarcacionCarnetService;
use App\Modules\Personas\Models\Colaborador;
use App\Modules\Personas\Services\ColaboradorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ControlAccesoController extends Controller
{
    public function __construct(
        private readonly RegistrarMarcacionCarnetService $marcaciones,
        private readonly CarnetCredentialService $credenciales,
        private readonly ColaboradorService $colaboradores,
    ) {}

    /** Kiosco — un escaneo, una respuesta. Nunca recibe fecha/hora/empresa/colaborador del cliente. */
    public function escanear(EscanearCarnetRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->marcaciones->registrar(
            $request->user('api')->empresa,
            $request->user('api'),
            $request->validated('codigo'),
        )]);
    }

    /** Estado de la credencial del colaborador — nunca expone el token ni su hash. */
    public function estadoCredencial(Request $request, Colaborador $colaborador): JsonResponse
    {
        abort_unless($colaborador->empresa_id === $request->user('api')->empresa->id, 404);

        $credencial = $this->credenciales->activaPara($colaborador);

        return response()->json(['data' => [
            'tiene_credencial_activa' => (bool) $credencial,
            'generado_at' => $credencial?->generado_at?->toDateTimeString(),
        ]]);
    }

    /** El `token` devuelto es la ÚNICA vez que se entrega en texto plano — el frontend lo usa de inmediato para generar el código de barras y no lo persiste. */
    public function generarCredencial(Request $request, Colaborador $colaborador): JsonResponse
    {
        abort_unless($colaborador->empresa_id === $request->user('api')->empresa->id, 404);

        ['credencial' => $credencial, 'token' => $token] = $this->credenciales->generar(
            $request->user('api')->empresa, $colaborador, $request->user('api'),
        );

        return response()->json(['data' => [
            'token' => $token,
            'generado_at' => $credencial->generado_at->toDateTimeString(),
        ]], 201);
    }

    public function regenerarCredencial(Request $request, Colaborador $colaborador): JsonResponse
    {
        abort_unless($colaborador->empresa_id === $request->user('api')->empresa->id, 404);

        ['credencial' => $credencial, 'token' => $token] = $this->credenciales->regenerar(
            $request->user('api')->empresa, $colaborador, $request->user('api'),
        );

        return response()->json(['data' => [
            'token' => $token,
            'generado_at' => $credencial->generado_at->toDateTimeString(),
        ]]);
    }

    public function revocarCredencial(RevocarCredencialCarnetRequest $request, Colaborador $colaborador): JsonResponse
    {
        abort_unless($colaborador->empresa_id === $request->user('api')->empresa->id, 404);

        $credencial = $this->credenciales->activaPara($colaborador);
        abort_if(! $credencial, 404, 'El colaborador no tiene una credencial de carnet activa.');

        $this->credenciales->revocar(
            $request->user('api')->empresa, $credencial, $request->user('api'), $request->validated('motivo'),
        );

        return response()->json(['message' => 'Credencial revocada.']);
    }

    /**
     * Búsqueda para el flujo "olvidó su carnet" — el vigilante encuentra a
     * la persona por nombre/documento, pero la CONFIRMACIÓN real pasa por
     * mirar la foto en pantalla, no por este listado. Por eso la respuesta
     * es deliberadamente mínima (nombre + cargo, nada de DNI completo ni
     * otros datos): Vigilancia no tiene `colaboradores.ver`, así que este
     * buscador no debe convertirse en una forma indirecta de consultar la
     * ficha completa de cualquier colaborador de la empresa.
     *
     * Colaborador NO tiene #[ScopedBy(EmpresaScope)] (ver el modelo) — acá
     * SÍ hace falta el `where('empresa_id', ...)` explícito, a diferencia
     * de CredencialAcceso/AsistenciaMarcacion que sí se scopean solos.
     */
    public function buscarColaboradores(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'buscar' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        $colaboradores = Colaborador::query()
            ->where('empresa_id', $request->user('api')->empresa->id)
            ->where('activo', true)
            ->where(function ($query) use ($datos) {
                $query->whereRaw("CONCAT(nombres, ' ', apellidos) LIKE ?", ["%{$datos['buscar']}%"])
                    ->orWhere('numero_documento', 'like', "%{$datos['buscar']}%");
            })
            ->orderBy('nombres')
            ->limit(10)
            ->get();

        return response()->json(['data' => $colaboradores->map(fn (Colaborador $colaborador) => [
            'id' => $colaborador->id,
            'nombre_mostrable' => trim("{$colaborador->nombres} {$colaborador->apellidos}"),
            'cargo' => $colaborador->cargo,
        ])]);
    }

    /**
     * Foto para la tarjeta de confirmación del buscador — endpoint propio
     * en vez de reutilizar `/colaboradores/{id}/foto-perfil` (ese exige
     * `colaboradores.ver`, permiso que Vigilancia no tiene y no debe
     * necesitar solo para ver una foto en el kiosco).
     */
    public function fotoColaborador(Request $request, Colaborador $colaborador)
    {
        abort_unless($colaborador->empresa_id === $request->user('api')->empresa->id, 404);

        $documento = $this->colaboradores->obtenerFotoPerfil($request->user('api')->empresa, $colaborador);

        abort_unless($documento && Storage::disk('local')->exists($documento->ruta), 404);

        return Storage::disk('local')->response($documento->ruta, $documento->nombre_original, [], 'inline');
    }

    /**
     * Registro manual — el vigilante ya buscó y confirmó visualmente al
     * colaborador (ver buscarColaboradores()/fotoColaborador()) antes de
     * llegar acá; este endpoint no vuelve a pedir ninguna prueba de
     * identidad porque esa verificación ya la hizo una persona, no un
     * código. Mismas reglas de negocio que el escaneo (antirebote, período
     * editable, colaborador activo) vía RegistrarMarcacionCarnetService.
     */
    public function marcarManual(Request $request, Colaborador $colaborador): JsonResponse
    {
        abort_unless($colaborador->empresa_id === $request->user('api')->empresa->id, 404);

        return response()->json(['data' => $this->marcaciones->registrarManual(
            $request->user('api')->empresa,
            $request->user('api'),
            $colaborador,
        )]);
    }
}
