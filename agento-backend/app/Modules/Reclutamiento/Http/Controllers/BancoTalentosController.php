<?php

namespace App\Modules\Reclutamiento\Http\Controllers;

use App\Modules\Reclutamiento\Application\BancoTalentosService;
use App\Modules\Reclutamiento\Http\Requests\InvitarRequest;
use App\Modules\Reclutamiento\Infrastructure\AlmacenDocumentosCandidato;
use App\Modules\Reclutamiento\Models\DocumentoCandidato;
use App\Modules\Reclutamiento\Http\Resources\CandidatoResource;
use App\Modules\Reclutamiento\Models\Candidato;
use App\Modules\Reclutamiento\Models\Etiqueta;
use App\Modules\Reclutamiento\Models\ProcesoSeleccion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BancoTalentosController extends SeleccionController
{
    public function __construct(
        private readonly BancoTalentosService $banco,
        private readonly AlmacenDocumentosCandidato $documentos,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $alcance = $this->alcance($request);

        return CandidatoResource::collection($this->banco->buscar($alcance, $request->only(['busqueda', 'cargo', 'area_id', 'competencia', 'etiqueta', 'estado_banco']), $this->porPagina($request)))
            ->additional(['etiquetas' => Etiqueta::where('empresa_id', $alcance->empresaId)->orderBy('nombre')->pluck('nombre')]);
    }

    public function show(Request $request, Candidato $candidato): CandidatoResource
    {
        $alcance = $this->alcance($request);

        return new CandidatoResource($this->banco->detalle($candidato, $alcance->puede('seleccion.documentos.ver')));
    }

    /**
     * Descarga autorizada (permiso seleccion.documentos.ver en la ruta). Ambos
     * modelos están acotados a la empresa activa; además el documento debe
     * pertenecer al candidato de la URL.
     */
    public function documento(Request $request, Candidato $candidato, DocumentoCandidato $documento): StreamedResponse
    {
        $this->alcance($request);
        abort_unless($documento->candidato_id === $candidato->id, 404);

        return $this->documentos->descargar($documento);
    }

    public function etiquetar(Request $request, Candidato $candidato): CandidatoResource
    {
        $datos = $request->validate(['nombre' => ['required', 'string', 'min:2', 'max:60']]);

        return new CandidatoResource($this->banco->etiquetar($this->alcance($request), $candidato, $datos['nombre']));
    }

    public function quitarEtiqueta(Request $request, Candidato $candidato, Etiqueta $etiqueta): CandidatoResource
    {
        return new CandidatoResource($this->banco->quitarEtiqueta($this->alcance($request), $candidato, $etiqueta));
    }

    public function coincidencias(Request $request, ProcesoSeleccion $proceso): JsonResponse
    {
        $this->alcance($request);

        return response()->json(['data' => $this->banco->coincidencias($proceso)->map(fn ($c) => [
            'candidato' => (new CandidatoResource($c['candidato']->setRelation('postulaciones', collect())))->resolve($request),
            'puntaje' => $c['puntaje'],
            'razones' => $c['razones'],
        ])]);
    }

    public function invitar(InvitarRequest $request, ProcesoSeleccion $proceso): JsonResponse
    {
        $datos = $request->validated();
        $candidato = isset($datos['candidato_id']) ? Candidato::findOrFail($datos['candidato_id']) : null;
        $email = $datos['email'] ?? $candidato?->email;

        $resultado = $this->banco->invitar($this->alcance($request), $proceso, $email, $candidato, $datos['tipo']);

        return response()->json(['data' => [
            'invitacion_id' => $resultado['invitacion']->id,
            'expira_at' => $resultado['invitacion']->expira_at->toIso8601String(),
            'correo_enviado' => $resultado['correo_enviado'],
            'enlace' => $resultado['correo_enviado'] ? null : $resultado['enlace'],
        ]], 201);
    }
}
