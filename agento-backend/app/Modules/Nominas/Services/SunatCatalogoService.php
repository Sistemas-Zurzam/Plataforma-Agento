<?php

namespace App\Modules\Nominas\Services;

use App\Models\User;
use App\Modules\Asistencia\Models\TipoAusencia;
use App\Modules\Nominas\Models\ConceptoRemuneracion;
use App\Modules\Nominas\Models\SunatMapeo;
use Illuminate\Support\Collection;

/**
 * Capa de equivalencia "valor interno de Agento" → "código oficial SUNAT"
 * para los catálogos que son enumeraciones fijas sin tabla propia
 * (tipo_documento, tipo_trabajador, regimen_laboral, tipo_comprobante_rh).
 * Las fuentes especializadas (conceptos_remuneracion.codigo_plame,
 * tipos_ausencia.codigo_sunat_suspension) NO pasan por acá — cada una
 * conserva su propio dueño de módulo; ver ConceptoRemuneracionService y
 * TipoAusenciaController respectivamente. `resumen()` sí las consulta,
 * únicamente para el tablero agregado.
 */
class SunatCatalogoService
{
    /**
     * @var array<int, string>
     */
    public const TIPOS_VALIDOS = ['tipo_documento', 'tipo_trabajador', 'regimen_laboral', 'tipo_comprobante_rh'];

    /**
     * Los 4 estados posibles de cualquier fila de Catálogos SUNAT — la
     * misma regla aplica a sunat_mapeos, tipos_ausencia y
     * conceptos_remuneracion, cada uno con sus propios campos de origen.
     *
     * - configurado: ya tiene código SUNAT.
     * - no_aplica: esa tabla SUNAT no rige este valor interno (nunca es
     *   "pendiente", no bloquea nada).
     * - bloqueado_por_modelo: Agento no captura hoy el dato necesario para
     *   determinar el código (requiere un cambio funcional en otro
     *   módulo, no una elección de catálogo).
     * - requiere_configuracion: existe una equivalencia SUNAT posible pero
     *   un administrador debe elegirla explícitamente (no es automatizable
     *   ni depende de un dato faltante en Agento).
     */
    public static function calcularEstado(bool $noAplica, bool $tieneCodigo, bool $bloqueadoPorModelo): string
    {
        if ($noAplica) {
            return 'no_aplica';
        }
        if ($tieneCodigo) {
            return 'configurado';
        }
        if ($bloqueadoPorModelo) {
            return 'bloqueado_por_modelo';
        }

        return 'requiere_configuracion';
    }

    public function mapeosPorTipo(string $tipo): Collection
    {
        return SunatMapeo::where('tipo', $tipo)->orderBy('id')->get();
    }

    public function actualizarMapeo(SunatMapeo $mapeo, array $datos, ?User $usuario = null): SunatMapeo
    {
        $mapeo->update([
            'codigo_sunat' => $datos['codigo_sunat'] ?? null,
            'descripcion_sunat' => $datos['descripcion_sunat'] ?? null,
            'activo' => $datos['activo'] ?? $mapeo->activo,
            'actualizado_por_id' => $usuario?->id,
        ]);

        return $mapeo->fresh();
    }

    /**
     * Tablero agregado de Catálogos SUNAT — cuenta los 4 estados reales
     * por cada fuente (mapeos genéricos + tipos_ausencia + conceptos
     * activos en Nóminas), nunca números de ejemplo. "no_aplica" nunca se
     * suma a `pendientes` (sección 32: pendientes_reales = requiere_
     * configuracion + bloqueados_por_modelo).
     *
     * @return array{total: int, configurados: int, requiere_configuracion: int, bloqueados_por_modelo: int, no_aplica: int, pendientes: int, cobertura_porcentaje: float, por_tipo: array<string, array>}
     */
    public function resumen(): array
    {
        $porTipo = [];

        foreach (self::TIPOS_VALIDOS as $tipo) {
            $mapeos = SunatMapeo::where('tipo', $tipo)->get();
            $porTipo[$tipo] = $this->contarEstados(
                $mapeos,
                fn (SunatMapeo $m) => ! $m->activo,
                fn (SunatMapeo $m) => filled($m->codigo_sunat),
                fn (SunatMapeo $m) => $m->bloqueado_por_modelo,
            );
        }

        $ausencias = TipoAusencia::all();
        $porTipo['suspensiones'] = $this->contarEstados(
            $ausencias,
            fn (TipoAusencia $t) => $t->sunat_no_aplica,
            fn (TipoAusencia $t) => filled($t->codigo_sunat_suspension),
            fn (TipoAusencia $t) => $t->sunat_bloqueado_por_modelo,
        );

        // Solo conceptos realmente usables en Nóminas (activo=true) — los
        // desactivados no son responsabilidad de Catálogos SUNAT.
        $conceptos = ConceptoRemuneracion::where('activo', true)->get();
        $porTipo['conceptos_plame'] = $this->contarEstados(
            $conceptos,
            fn (ConceptoRemuneracion $c) => $c->sunat_no_aplica,
            fn (ConceptoRemuneracion $c) => filled($c->codigo_plame),
            fn (ConceptoRemuneracion $c) => $c->sunat_bloqueado_por_modelo,
        );

        $total = array_sum(array_column($porTipo, 'total'));
        $configurados = array_sum(array_column($porTipo, 'configurados'));
        $requiereConfiguracion = array_sum(array_column($porTipo, 'requiere_configuracion'));
        $bloqueados = array_sum(array_column($porTipo, 'bloqueados_por_modelo'));
        $noAplica = array_sum(array_column($porTipo, 'no_aplica'));

        // Cobertura: excluye "no_aplica" tanto del numerador como del
        // denominador (sección 33) — no es un pendiente ni un logro, es
        // "esta pregunta no corresponde acá".
        $baseCobertura = $configurados + $requiereConfiguracion + $bloqueados;

        return [
            'total' => $total,
            'configurados' => $configurados,
            'requiere_configuracion' => $requiereConfiguracion,
            'bloqueados_por_modelo' => $bloqueados,
            'no_aplica' => $noAplica,
            // pendientes_reales (sección 32): nunca incluye no_aplica.
            'pendientes' => $requiereConfiguracion + $bloqueados,
            'cobertura_porcentaje' => $baseCobertura > 0 ? round($configurados / $baseCobertura * 100, 1) : 100.0,
            'por_tipo' => $porTipo,
        ];
    }

    /**
     * @param  Collection<int, object>  $items
     * @return array{total: int, configurados: int, requiere_configuracion: int, bloqueados_por_modelo: int, no_aplica: int}
     */
    private function contarEstados(Collection $items, callable $esNoAplica, callable $tieneCodigo, callable $esBloqueado): array
    {
        $estados = $items->map(fn ($item) => self::calcularEstado($esNoAplica($item), $tieneCodigo($item), $esBloqueado($item)));

        return [
            'total' => $items->count(),
            'configurados' => $estados->filter(fn ($e) => $e === 'configurado')->count(),
            'requiere_configuracion' => $estados->filter(fn ($e) => $e === 'requiere_configuracion')->count(),
            'bloqueados_por_modelo' => $estados->filter(fn ($e) => $e === 'bloqueado_por_modelo')->count(),
            'no_aplica' => $estados->filter(fn ($e) => $e === 'no_aplica')->count(),
        ];
    }
}
