<?php

namespace App\Modules\Reclutamiento\Application;

use App\Modules\Reclutamiento\Domain\CalculadoraAts;
use App\Modules\Reclutamiento\Domain\Contracts\ProveedorCompatibilidad;
use App\Modules\Reclutamiento\Models\EvaluacionAts;
use App\Modules\Reclutamiento\Models\PerfilProceso;
use App\Modules\Reclutamiento\Models\Postulacion;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orquesta el cálculo ATS. La evidencia puede venir de:
 * - "reglas_declaradas": requisitos y habilidades declarados por el
 *   candidato al postular (cálculo determinista, sin IA);
 * - "proveedor": un ProveedorCompatibilidad configurado (si existe);
 * - "manual": evaluación trazable de RR. HH.
 * En todos los casos el puntaje lo calcula CalculadoraAts (fórmula versionada).
 */
class EvaluacionAtsService
{
    public function __construct(
        private readonly CalculadoraAts $calculadora,
        private readonly ProveedorCompatibilidad $proveedor,
        private readonly PuntajeFinalService $puntajes,
        private readonly EtiquetadoAutomatico $etiquetas,
    ) {}

    /**
     * Cálculo inicial al postular. Un fallo del proveedor nunca bloquea la
     * postulación: se registra como error y se cae al cálculo por reglas.
     */
    public function calcularInicial(Postulacion $postulacion): EvaluacionAts
    {
        $perfil = $this->perfil($postulacion);
        $snapshot = $postulacion->perfil_snapshot ?? [];
        $origen = 'reglas_declaradas';
        $proveedor = 'reglas';
        $evidencia = null;

        if ($this->proveedor->disponible()) {
            try {
                $evidencia = $this->proveedor->evaluar($this->perfilComoDatos($perfil), $snapshot);
                $origen = 'proveedor';
                $proveedor = $this->proveedor->nombre();
            } catch (Throwable $e) {
                Log::warning('Reclutamiento: el proveedor ATS falló; se usa el cálculo por reglas.', [
                    'postulacion_id' => $postulacion->id, 'error' => class_basename($e),
                ]);
            }
        }

        $requisitos = $perfil->requisitos->map(fn ($r) => [
            'id' => $r->id,
            'tipo' => $r->tipo,
            'descripcion' => $r->descripcion,
            'resultado' => $evidencia['requisitos'][$r->id] ?? $this->resultadoDeclarado($snapshot, $r->id),
        ])->all();

        $habilidadesDeclaradas = collect($snapshot['habilidades'] ?? [])
            ->mapWithKeys(fn ($h) => [CalculadoraAts::normalizar($h['nombre']) => $h['nivel'] ?? null]);

        $competencias = $perfil->competencias->map(function ($c) use ($evidencia, $habilidadesDeclaradas) {
            $clave = CalculadoraAts::normalizar($c->nombre);

            return [
                'nombre' => $c->nombre,
                'nivel_requerido' => $c->nivel,
                'peso' => $c->peso,
                'nivel_candidato' => $evidencia['competencias'][$clave] ?? $habilidadesDeclaradas->get($clave),
            ];
        })->all();

        return $this->registrar($postulacion, $perfil, $requisitos, $competencias, $origen, $proveedor, null);
    }

    /**
     * Evaluación manual de RR. HH.: resultado por requisito y nivel por
     * competencia. Crea una fila nueva (historial), no edita la anterior.
     *
     * @param  array<int, string>  $resultadosRequisitos  requisito_id => cumple|no_cumple|sin_informacion
     * @param  array<string, int|null>  $nivelesCompetencias  nombre => nivel
     */
    public function evaluarManual(Postulacion $postulacion, array $resultadosRequisitos, array $nivelesCompetencias, int $usuarioId, ?string $comentario): EvaluacionAts
    {
        $perfil = $this->perfil($postulacion);
        $niveles = collect($nivelesCompetencias)->mapWithKeys(fn ($nivel, $nombre) => [CalculadoraAts::normalizar((string) $nombre) => $nivel]);

        $requisitos = $perfil->requisitos->map(fn ($r) => [
            'id' => $r->id,
            'tipo' => $r->tipo,
            'descripcion' => $r->descripcion,
            'resultado' => $resultadosRequisitos[$r->id] ?? 'sin_informacion',
        ])->all();

        $competencias = $perfil->competencias->map(fn ($c) => [
            'nombre' => $c->nombre,
            'nivel_requerido' => $c->nivel,
            'peso' => $c->peso,
            'nivel_candidato' => $niveles->get(CalculadoraAts::normalizar($c->nombre)),
        ])->all();

        return $this->registrar($postulacion, $perfil, $requisitos, $competencias, 'manual', 'evaluacion_manual', $usuarioId, $comentario);
    }

    private function registrar(Postulacion $postulacion, PerfilProceso $perfil, array $requisitos, array $competencias, string $origen, string $proveedor, ?int $usuarioId, ?string $comentario = null): EvaluacionAts
    {
        $resultado = $this->calculadora->calcular($requisitos, $competencias, $perfil->umbral_ats);

        $evaluacion = EvaluacionAts::create([
            'empresa_id' => $postulacion->empresa_id,
            'postulacion_id' => $postulacion->id,
            'perfil_version' => $perfil->version,
            'puntaje' => $resultado['puntaje'],
            'resultado' => $resultado['resultado'],
            'desglose' => [...$resultado['desglose'], 'comentario' => $comentario],
            'formula' => CalculadoraAts::FORMULA,
            'proveedor' => $proveedor,
            'origen' => $origen,
            'evaluado_por' => $usuarioId,
        ]);

        $this->puntajes->recalcular($postulacion);
        if ($resultado['resultado'] === 'apto') {
            $this->etiquetas->aplicar($postulacion->empresa_id, $postulacion->candidato_id, 'apto_ats');
        }

        return $evaluacion;
    }

    /**
     * Las declaraciones del candidato sobre requisitos (sí/no). Sin
     * declaración no se asume incumplimiento: queda "sin_informacion".
     */
    private function resultadoDeclarado(array $snapshot, int $requisitoId): string
    {
        $declaracion = $snapshot['requisitos'][$requisitoId] ?? null;

        return match ($declaracion) {
            true, 'si', 1, '1' => 'cumple',
            false, 'no', 0, '0' => 'no_cumple',
            default => 'sin_informacion',
        };
    }

    private function perfil(Postulacion $postulacion): PerfilProceso
    {
        return PerfilProceso::where('proceso_id', $postulacion->proceso_id)
            ->orderByDesc('version')
            ->with(['requisitos', 'competencias'])
            ->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function perfilComoDatos(PerfilProceso $perfil): array
    {
        return [
            'requisitos' => $perfil->requisitos->map->only(['id', 'tipo', 'descripcion'])->all(),
            'competencias' => $perfil->competencias->map->only(['nombre', 'nivel'])->all(),
        ];
    }
}
