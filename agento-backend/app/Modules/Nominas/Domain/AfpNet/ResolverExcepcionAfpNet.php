<?php

namespace App\Modules\Nominas\Domain\AfpNet;

use App\Modules\Asistencia\Models\AsistenciaPermiso;
use App\Modules\Nominas\Models\CicloRemunerativo;
use App\Modules\Personas\Models\Colaborador;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Traduce el hecho "remuneración asegurable en cero + permisos del
 * período" al código de Excepción de Aportar de AFPnet — semántica
 * PROPIA de AFPnet, nunca reutiliza ResolverSuspensionSunat (Tabla 21 de
 * PLAME resuelve algo distinto: días de suspensión declarables en .snl,
 * no "por qué no corresponde aportar a la AFP este mes"). Ni siquiera para
 * el umbral de 20 días de descanso médico: acá se confía por completo en
 * el hecho `pagador_subsidio` que registra RR.HH., nunca se fracciona el
 * permiso según la regla PLAME.
 *
 * Regla principal: remuneración asegurable > 0 → SIEMPRE corresponde
 * aportar (excepción vacía), sin importar cuántas ausencias parciales
 * hubo. Solo cuando la remuneración es 0 se intenta explicar por qué, y
 * solo si la COBERTURA CONSOLIDADA de los permisos aplicables (fusionando
 * intervalos contiguos/superpuestos, sin duplicar días) cubre por
 * completo la ventana relevante del colaborador en el período — la
 * intersección de [fecha_ingreso, fecha_cese] con [ciclo.fecha_inicio,
 * ciclo.fecha_fin] — nunca por un solo evento parcial ni por la suma
 * ingenua de días de N registros.
 *
 * J (jubilación) e I (invalidez) NO se determinan: Agento no modela
 * situación pensionaria. P (ingreso tras cierre) tampoco: no existe una
 * fecha de "cierre de planilla AFPnet" confiable en CicloRemunerativo
 * (fecha_corte_asistencia es de asistencia, no de declaración AFP — no se
 * reutiliza). Ambos quedan BACKLOG explícito, nunca inferidos.
 */
final class ResolverExcepcionAfpNet
{
    public const SIN_EXCEPCION = '';

    public const LICENCIA_SIN_GOCE = 'L';

    public const SUBSIDIO_ESSALUD_DIRECTO = 'U';

    public const OTRA_SITUACION = 'O';

    /**
     * @param  Collection<int, AsistenciaPermiso>  $permisosDelPeriodo  Aprobados, con tipoAusencia cargado, que se solapan con el ciclo.
     * @return array{codigo: ?string, determinado: bool, motivo: ?string, accion: ?string}
     */
    public static function resolver(Colaborador $colaborador, CicloRemunerativo $ciclo, float $remuneracionAsegurable, Collection $permisosDelPeriodo): array
    {
        if ($remuneracionAsegurable > 0) {
            return ['codigo' => self::SIN_EXCEPCION, 'determinado' => true, 'motivo' => null, 'accion' => null];
        }

        $ventana = self::ventanaRelevante($colaborador, $ciclo);
        if ($ventana === null) {
            // Sin ventana relevante (ej. cesó antes de que empezara el
            // ciclo) — dato incoherente para tener boleta este período, no
            // se arriesga ninguna clasificación.
            return [
                'codigo' => null,
                'determinado' => false,
                'motivo' => 'No se pudo determinar una ventana laboral relevante del colaborador dentro del período (revisar fecha_ingreso/fecha_cese).',
                'accion' => 'Revisar fecha de ingreso/cese del colaborador contra el período del ciclo.',
            ];
        }

        $intervalosSinGoce = self::intervalosAplicables(
            $permisosDelPeriodo,
            fn (AsistenciaPermiso $p) => $p->tipoAusencia?->codigo !== 'medico' && $p->con_goce === false,
        );
        if (self::cubreVentanaCompleta($intervalosSinGoce, $ventana)) {
            return ['codigo' => self::LICENCIA_SIN_GOCE, 'determinado' => true, 'motivo' => null, 'accion' => null];
        }

        $permisosMedicosRelevantes = $permisosDelPeriodo->filter(
            fn (AsistenciaPermiso $p) => $p->tipoAusencia?->codigo === 'medico' && self::seSolapaConVentana($p, $ventana),
        );

        $intervalosSubsidioDirecto = self::intervalosAplicables(
            $permisosMedicosRelevantes,
            fn (AsistenciaPermiso $p) => $p->pagador_subsidio === 'essalud_directo',
        );
        if (self::cubreVentanaCompleta($intervalosSubsidioDirecto, $ventana)) {
            return ['codigo' => self::SUBSIDIO_ESSALUD_DIRECTO, 'determinado' => true, 'motivo' => null, 'accion' => null];
        }

        // Resguardo obligatorio (Sección 0 del encargo): un permiso médico
        // que se solapa con el período de remuneración cero y todavía no
        // tiene pagador_subsidio confirmado NUNCA cae en "O" — eso
        // ocultaría un dato faltante real. Se bloquea explícitamente antes
        // de llegar a la regla segura de O.
        $medicoSinPagadorConfirmado = $permisosMedicosRelevantes->first(fn (AsistenciaPermiso $p) => $p->pagador_subsidio === null);
        if ($medicoSinPagadorConfirmado) {
            return [
                'codigo' => null,
                'determinado' => false,
                'motivo' => 'Debe indicar quién realizó el pago del subsidio.',
                'accion' => 'Completar el permiso médico en Asistencia.',
            ];
        }

        // Regla segura de "O": llegar hasta acá ya implica boleta vigente +
        // línea AFP_APORTE_OBLIGATORIO existente + base_utilizada = 0
        // confirmado (AfpNetValidator garantiza esto antes de invocar al
        // resolver), que L/U no explican el período completo, y que
        // ningún permiso médico relevante quedó con pagador sin confirmar
        // — "O" es la conclusión honesta ("no hubo remuneración en el mes
        // y Agento no identifica una causa propia"), no una forma de
        // esconder un dato faltante.
        return ['codigo' => self::OTRA_SITUACION, 'determinado' => true, 'motivo' => null, 'accion' => null];
    }

    /**
     * Intersección de [fecha_ingreso, fecha_cese] con el período del
     * ciclo — nunca se evalúa cobertura fuera de los días en que el
     * colaborador realmente pudo tener relación laboral.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function ventanaRelevante(Colaborador $colaborador, CicloRemunerativo $ciclo): ?array
    {
        $inicio = $colaborador->fecha_ingreso?->toDateString() ?? $ciclo->fecha_inicio->toDateString();
        $fin = $colaborador->fecha_cese?->toDateString() ?? $ciclo->fecha_fin->toDateString();

        $desde = max($inicio, $ciclo->fecha_inicio->toDateString());
        $hasta = min($fin, $ciclo->fecha_fin->toDateString());

        return $desde <= $hasta ? [$desde, $hasta] : null;
    }

    /**
     * @param  array{0: string, 1: string}  $ventana
     */
    private static function seSolapaConVentana(AsistenciaPermiso $permiso, array $ventana): bool
    {
        [$ventanaInicio, $ventanaFin] = $ventana;

        return $permiso->fecha_inicio->toDateString() <= $ventanaFin
            && $permiso->fecha_fin->toDateString() >= $ventanaInicio;
    }

    /**
     * @param  Collection<int, AsistenciaPermiso>  $permisos
     * @return array<int, array{0: string, 1: string}>
     */
    private static function intervalosAplicables(Collection $permisos, callable $filtro): array
    {
        return $permisos->filter($filtro)
            ->map(fn (AsistenciaPermiso $p) => [$p->fecha_inicio->toDateString(), $p->fecha_fin->toDateString()])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array{0: string, 1: string}>  $intervalos
     * @param  array{0: string, 1: string}  $ventana
     */
    private static function cubreVentanaCompleta(array $intervalos, array $ventana): bool
    {
        [$ventanaInicio, $ventanaFin] = $ventana;

        $recortados = [];
        foreach ($intervalos as [$inicio, $fin]) {
            $desde = max($inicio, $ventanaInicio);
            $hasta = min($fin, $ventanaFin);
            if ($desde <= $hasta) {
                $recortados[] = [$desde, $hasta];
            }
        }

        if ($recortados === []) {
            return false;
        }

        $fusionados = self::fusionarIntervalos($recortados);

        // Cobertura completa = un único bloque fusionado que coincide
        // exacto con la ventana — si quedó más de un bloque, hay un hueco
        // sin cubrir dentro del período (Sección 2: no contar días
        // duplicados, no asumir cobertura por suma ingenua).
        return count($fusionados) === 1
            && $fusionados[0][0] === $ventanaInicio
            && $fusionados[0][1] === $ventanaFin;
    }

    /**
     * Ordena y fusiona intervalos contiguos/superpuestos sin duplicar
     * días — resuelto siempre desde los eventos reales, nunca desde un
     * contador acumulado guardado aparte.
     *
     * @param  array<int, array{0: string, 1: string}>  $intervalos
     * @return array<int, array{0: string, 1: string}>
     */
    private static function fusionarIntervalos(array $intervalos): array
    {
        if ($intervalos === []) {
            return [];
        }

        usort($intervalos, fn (array $a, array $b) => $a[0] <=> $b[0]);

        $fusionados = [array_shift($intervalos)];
        foreach ($intervalos as [$inicio, $fin]) {
            $ultimo = count($fusionados) - 1;
            $contiguoOSolapado = $inicio <= Carbon::parse($fusionados[$ultimo][1])->addDay()->toDateString();

            if ($contiguoOSolapado) {
                $fusionados[$ultimo][1] = max($fusionados[$ultimo][1], $fin);
            } else {
                $fusionados[] = [$inicio, $fin];
            }
        }

        return $fusionados;
    }
}
