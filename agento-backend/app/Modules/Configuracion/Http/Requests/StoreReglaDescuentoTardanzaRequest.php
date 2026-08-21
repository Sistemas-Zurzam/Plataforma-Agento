<?php

namespace App\Modules\Configuracion\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreReglaDescuentoTardanzaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'minutos_desde' => ['required', 'integer', 'min:0'],
            'minutos_hasta' => ['nullable', 'integer', 'gt:minutos_desde'],
            'tipo' => ['required', Rule::in(['por_minuto', 'monto_fijo', 'medio_dia', 'dia_completo'])],
            'valor' => ['nullable', 'numeric', 'min:0', 'required_if:tipo,por_minuto,monto_fijo'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $empresa = $this->route('empresa');
            $desde = (int) $this->input('minutos_desde');
            $hasta = $this->input('minutos_hasta') !== null ? (int) $this->input('minutos_hasta') : null;

            // Evita reglas que se solapen entre sí — cada rango de minutos
            // de tardanza debe resolver a una única regla, nunca ambigua.
            $solapa = $empresa->reglasDescuentoTardanza()
                ->where(function ($query) use ($desde, $hasta) {
                    $query->where('minutos_desde', '<=', $hasta ?? PHP_INT_MAX)
                        ->where(fn ($q) => $q->whereNull('minutos_hasta')->orWhere('minutos_hasta', '>=', $desde));
                })
                ->exists();

            if ($solapa) {
                $validator->errors()->add('minutos_desde', 'Este rango se solapa con una regla ya configurada.');
            }
        });
    }
}
