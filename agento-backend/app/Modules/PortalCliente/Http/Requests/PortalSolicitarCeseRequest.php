<?php

namespace App\Modules\PortalCliente\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * Evidencias: 1–5 archivos PDF/JPG/PNG de hasta config('portal_cliente.ceses.max_kb')
 * (10 MB por defecto: supuesto operativo, mismo tope que los documentos del
 * legajo). El contenido real se vuelve a verificar al guardar
 * (AlmacenEvidenciasCese). "Hoy" se evalúa en la zona horaria del negocio.
 */
class PortalSolicitarCeseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $hoy = Carbon::now(config('portal_cliente.zona_horaria_negocio'))->toDateString();
        $maxKb = (int) config('portal_cliente.ceses.max_kb');

        return [
            'colaborador_id' => ['required', 'integer'],
            'fecha_cese_propuesta' => ['required', 'date_format:Y-m-d', "after_or_equal:{$hoy}"],
            'motivo' => ['required', 'string', 'min:10', 'max:1000'],
            'evidencias' => ['required', 'array', 'min:1', 'max:'.(int) config('portal_cliente.ceses.max_evidencias')],
            'evidencias.*' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'extensions:pdf,jpg,jpeg,png', "max:{$maxKb}"],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['fecha_cese_propuesta.after_or_equal' => 'La fecha de cese propuesta debe ser hoy o posterior.'];
    }
}
