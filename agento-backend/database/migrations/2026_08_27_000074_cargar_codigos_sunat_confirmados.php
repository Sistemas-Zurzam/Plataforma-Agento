<?php

use App\Modules\Nominas\Models\ConceptoRemuneracion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Carga ÚNICAMENTE los códigos SUNAT confirmados por evidencia directa
     * en el Anexo 3 (Anexo3_estructuras_Archivos_Importación JUL23_c.xls),
     * encontrados repetidos de forma idéntica en las estructuras E4, E5,
     * E7, E18, E20 y E26 ("Ver tabla 3. Solo tipo 01 - DNI; 04 – Carné de
     * Extranjería; 07 - Pasaporte; ..."). Ningún otro código se inventa:
     * donde solo hay evidencia parcial (ej. AFP en Tabla 22) se deja
     * documentado en descripcion_sunat SIN código, y donde la estructura
     * PLAME real no aplica (ej. honorarios no usan Tabla 22, locador/
     * practicante no tienen código de Tabla 8 propio) se marca así
     * explícitamente en vez de dejarlo como "pendiente" engañoso.
     *
     * Los WHERE codigo_sunat IS NULL evitan pisar cualquier valor que un
     * administrador ya haya configurado manualmente desde la UI.
     */
    public function up(): void
    {
        // Tabla 3 — tipo de documento (evidencia: E4 fila 8, E5 fila 9,
        // E7 fila 13, E18 fila 13, E20 fila 13, E26 fila 14 — idéntico en
        // las 6 estructuras).
        $documentos = [
            'dni' => ['01', 'Documento Nacional de Identidad'],
            'ce' => ['04', 'Carné de Extranjería'],
            'pasaporte' => ['07', 'Pasaporte'],
        ];
        foreach ($documentos as $claveInterna => [$codigo, $descripcion]) {
            DB::table('sunat_mapeos')
                ->where('tipo', 'tipo_documento')
                ->where('clave_interna', $claveInterna)
                ->whereNull('codigo_sunat')
                ->update(['codigo_sunat' => $codigo, 'descripcion_sunat' => $descripcion, 'updated_at' => now()]);
        }

        // Tabla 8 — tipo de trabajador: "locador" y "practicante" NO
        // corresponden a un código de Tabla 8 dentro de E5 (.tra) — SUNAT
        // los declara mediante estructuras T-Registro/PLAME distintas
        // (E9 "Personal en Formación" para practicante; E7/.ps4 y
        // E20/.4ta "Prestador de Servicios 4ta categoría" para locador,
        // que ni siquiera tienen un campo "tipo de trabajador"). Marcarlos
        // "Sin configurar" sería engañoso — se desactivan con la
        // explicación funcional. "trabajador" sigue activo y pendiente: el
        // código base de Tabla 8 para un dependiente D.Leg 728 no aparece
        // en ningún lugar del Anexo 3 disponible.
        DB::table('sunat_mapeos')
            ->where('tipo', 'tipo_trabajador')
            ->where('clave_interna', 'locador')
            ->whereNull('codigo_sunat')
            ->update([
                'activo' => false,
                'descripcion_sunat' => 'No aplica a Tabla 8: los prestadores de 4ta categoría se declaran mediante las estructuras E7 (.ps4) y E20 (.4ta), que no tienen campo "tipo de trabajador".',
                'updated_at' => now(),
            ]);

        DB::table('sunat_mapeos')
            ->where('tipo', 'tipo_trabajador')
            ->where('clave_interna', 'practicante')
            ->whereNull('codigo_sunat')
            ->update([
                'activo' => false,
                'descripcion_sunat' => 'No aplica a Tabla 8 vía E5: el personal en formación - modalidad formativa laboral se declara mediante la Estructura 9 (T-Registro), separada de "trabajador".',
                'updated_at' => now(),
            ]);

        // Tabla 21 — evidencia parcial (E15, fila 16): los códigos 21 y 22
        // corresponden a "días subsidiados por EsSalud" en conjunto, pero
        // el Anexo 3 no distingue cuál de los dos es específicamente
        // descanso médico frente a otro subsidio — no se asigna ninguno,
        // solo se documenta la pista para quien complete el catálogo.
        DB::table('tipos_ausencia')
            ->where('codigo', 'medico')
            ->whereNull('codigo_sunat_suspension')
            ->update([
                'descripcion_sunat' => 'Evidencia parcial (E15 Anexo 3): los códigos 21 y 22 de Tabla 21 son "días subsidiados por EsSalud", pero no se pudo determinar cuál corresponde específicamente a descanso médico sin el detalle completo de la tabla.',
                'updated_at' => now(),
            ]);

        // Tabla 22 — evidencia parcial (E18, fila 29 y notas 25-28): los
        // conceptos AFP (0601, 0606, 0608, 0609) están confirmados como
        // exclusivos de Sistema Privado de Pensiones, pero son 4 códigos
        // para 3 conceptos AFP de Agento — no se puede determinar sin
        // ambigüedad cuál corresponde a cada uno.
        $conceptosAfp = ConceptoRemuneracion::whereIn('codigo', ['AFP_APORTE_OBLIGATORIO', 'AFP_PRIMA_SEGURO', 'AFP_COMISION'])->get();
        foreach ($conceptosAfp as $concepto) {
            // La migración 000073 ya insertó 1 fila de backfill (vacía) por
            // cada concepto — si ya hay más de esa, alguien ya editó este
            // concepto de verdad y no se debe pisar con esta anotación.
            if ($concepto->codigosPlameHistorial()->count() > 1) {
                continue;
            }
            $concepto->codigosPlameHistorial()->create([
                'codigo_plame' => null,
                'descripcion_sunat' => 'Evidencia parcial (E18 Anexo 3): pertenece a uno de los códigos 0601/0606/0608/0609 de Tabla 22 (reservados a Sistema Privado de Pensiones), pero no se pudo determinar cuál corresponde a este concepto específico.',
                'vigencia_desde' => now()->toDateString(),
            ]);
        }

        // Los honorarios (RH) NO se declaran vía Tabla 22 — E20 (.4ta) los
        // reporta directamente como "Monto total del servicio", sin código
        // de concepto. Marcarlos "pendiente" sería engañoso.
        $conceptosRh = ConceptoRemuneracion::whereIn('codigo', ['HONORARIO_BRUTO', 'RETENCION_RENTA_4TA'])->get();
        foreach ($conceptosRh as $concepto) {
            // La migración 000073 ya insertó 1 fila de backfill (vacía) por
            // cada concepto — si ya hay más de esa, alguien ya editó este
            // concepto de verdad y no se debe pisar con esta anotación.
            if ($concepto->codigosPlameHistorial()->count() > 1) {
                continue;
            }
            $concepto->codigosPlameHistorial()->create([
                'codigo_plame' => null,
                'descripcion_sunat' => 'No aplica a Tabla 22: los honorarios se declaran directamente en la estructura E20 (.4ta) como "Monto total del servicio" y su indicador de retención, sin código de concepto remunerativo.',
                'vigencia_desde' => now()->toDateString(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Data-fix: no se revierte automáticamente (podría borrar
        // correcciones manuales posteriores del administrador).
    }
};
