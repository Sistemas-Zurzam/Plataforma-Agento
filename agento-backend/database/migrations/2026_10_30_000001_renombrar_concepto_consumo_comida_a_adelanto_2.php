<?php

use App\Modules\Nominas\Models\ConceptoRemuneracion;
use Illuminate\Database\Migrations\Migration;

/**
 * En la boleta el descuento por consumo de comida se presenta como
 * "Adelanto 2". Solo cambia el nombre visible: el código, la clasificación
 * (egreso no remunerativo) y las bases afectadas no se tocan. La boleta lee
 * el nombre del concepto en vivo, así que aplica también a las ya calculadas.
 */
return new class extends Migration
{
    public function up(): void
    {
        ConceptoRemuneracion::where('codigo', 'DESCUENTO_CONSUMO_COMIDA')
            ->update(['nombre' => 'Adelanto 2']);
    }

    public function down(): void
    {
        ConceptoRemuneracion::where('codigo', 'DESCUENTO_CONSUMO_COMIDA')
            ->update(['nombre' => 'Descuento por consumo de comida']);
    }
};
