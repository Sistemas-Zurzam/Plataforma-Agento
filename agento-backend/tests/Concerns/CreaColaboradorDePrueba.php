<?php

namespace Tests\Concerns;

use App\Modules\Asistencia\Models\Horario;
use App\Modules\Configuracion\Models\Area;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\Sede;
use App\Modules\Personas\Models\Colaborador;
use App\Modules\Personas\Models\ColaboradorRemuneracion;

trait CreaColaboradorDePrueba
{
    private function crearColaborador(?Empresa $empresa = null, array $atributos = []): Colaborador
    {
        $empresa ??= Empresa::firstOrFail();
        $sufijo = uniqid();
        $sede = Sede::create(['empresa_id' => $empresa->id, 'codigo' => "LIQ-{$sufijo}", 'nombre' => 'Sede Test', 'activa' => true]);
        $area = Area::create(['empresa_id' => $empresa->id, 'nombre' => "Área Test {$sufijo}", 'activa' => true]);
        $horario = Horario::create(['empresa_id' => $empresa->id, 'nombre' => 'Horario Test', 'tipo_turno' => 'normal', 'vigencia_desde' => today(), 'activo' => true]);
        $colaborador = Colaborador::create([
            'empresa_id' => $empresa->id, 'sede_id' => $sede->id, 'area_id' => $area->id, 'horario_id' => $horario->id,
            'legajo' => "LIQ-{$sufijo}", 'nombres' => 'Prueba', 'apellidos' => 'Liquidación', 'tipo_documento' => 'dni',
            'numero_documento' => $sufijo, 'celular_colaborador' => '900000000', 'celular_referencia' => '900000001',
            'cargo' => 'Analista', 'tipo_contrato' => 'indefinido', 'regimen_laboral' => 'General',
            'tipo_trabajador' => 'trabajador', 'fecha_ingreso' => now()->subMonths(3)->startOfMonth(),
            'modalidad_trabajo' => 'presencial', 'activo' => true,
            // ONP evita la rama AFP de calcularAporteAfpOnp(), que exige una
            // Remuneración Máxima Asegurable (RMA) configurada por empresa —
            // un dato de negocio real que no es responsabilidad de este
            // fixture sembrar; los tests que sí necesiten ejercitar AFP deben
            // pasar 'sistema_previsional' explícito en $atributos.
            'sistema_previsional' => 'onp',
            ...$atributos,
        ]);
        ColaboradorRemuneracion::create([
            'colaborador_id' => $colaborador->id, 'salario' => 3000, 'moneda_salario' => 'PEN',
            'periodicidad_pago' => 'mensual', 'vigencia_desde' => $colaborador->fecha_ingreso,
        ]);

        return $colaborador->load('empresa');
    }
}
