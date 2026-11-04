<?php

namespace App\Modules\PortalCliente\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mismo catálogo y reglas que StoreAsistenciaPermisoRequest (Asistencia),
 * con estas diferencias deliberadas del portal:
 * - Sin 'vacaciones': tienen su propio dominio (saldos), aún no disponible.
 * - Sin pagador_subsidio: es una determinación de RR. HH.
 * - Sin estado/origen/registrado_por: los asigna el servidor.
 * - modalidad 'por_horas' se rechaza: el motor de Asistencia y Nómina solo
 *   procesan permisos por día completo (ver entrega).
 */
class PortalSolicitarPermisoRequest extends FormRequest
{
    public const TIPOS = ['personal', 'medico', 'capacitacion', 'comision_servicio', 'otro'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'colaborador_id' => ['required', 'integer'],
            'tipo' => ['required', Rule::in(self::TIPOS)],
            'modalidad' => ['required', Rule::in(['dia_completo', 'por_horas'])],
            'fecha_inicio' => ['required', 'date_format:Y-m-d'],
            'fecha_fin' => ['required', 'date_format:Y-m-d', 'after_or_equal:fecha_inicio'],
            'motivo' => ['required', 'string', 'min:5', 'max:1000'],
            'con_goce' => [Rule::requiredIf(in_array($this->input('tipo'), ['personal', 'capacitacion'], true)), 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->input('modalidad') === 'por_horas') {
                $validator->errors()->add('modalidad', 'Los permisos por horas aún no están soportados por el procesamiento de asistencia y planilla. Registra el permiso por día completo o coordina con RR. HH.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['colaborador_id' => 'colaborador', 'fecha_inicio' => 'fecha inicial', 'fecha_fin' => 'fecha final', 'con_goce' => 'con goce'];
    }
}
