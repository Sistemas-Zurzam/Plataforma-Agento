<?php

namespace Tests\Feature\Modules\Nominas;

use App\Modules\Asistencia\Models\AsistenciaIncidencia;
use App\Modules\Asistencia\Models\AsistenciaResultadoDiario;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\BoletaConcepto;
use App\Modules\Nominas\Models\CicloRemunerativo;
use App\Modules\Nominas\Models\ConceptoRemuneracion;
use App\Modules\Nominas\Services\BeneficioSocialService;
use App\Modules\Personas\Models\ColaboradorCondicionLaboral;
use App\Modules\Personas\Models\ColaboradorRemuneracion;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\TestCase;

class BeneficioSocialCtsCalculoTest extends TestCase
{
    use RefreshDatabase,CreaColaboradorDePrueba;
    protected function setUp():void{parent::setUp();$this->seed(DatabaseSeeder::class);}

    private function crearSemestre(array $salarios):array
    {
        $e=Empresa::factory()->create(['regimen_laboral'=>'General']);$c=$this->crearColaborador($e,['fecha_ingreso'=>'2025-11-01','regimen_laboral'=>'General']);ColaboradorCondicionLaboral::create(['colaborador_id'=>$c->id,'regimen_laboral'=>'General','vigencia_desde'=>'2025-11-01']);
        foreach($salarios as $mes=>$salario)ColaboradorRemuneracion::create(['colaborador_id'=>$c->id,'salario'=>$salario,'moneda_salario'=>'PEN','periodicidad_pago'=>'mensual','vigencia_desde'=>"2026-$mes-01"]);
        $concepto=ConceptoRemuneracion::where('codigo','SUELDO_BASICO')->firstOrFail();$boletas=[];
        foreach(['05','06','07','08','09','10'] as $mes){$ciclo=CicloRemunerativo::create(['empresa_id'=>$e->id,'nombre'=>"Mes $mes",'periodicidad'=>'mensual','fecha_inicio'=>"2026-$mes-01",'fecha_fin'=>"2026-$mes-".cal_days_in_month(CAL_GREGORIAN,(int)$mes,2026),'fecha_corte_asistencia'=>"2026-$mes-28",'fecha_pago'=>"2026-$mes-30",'estado'=>'pagado']);$b=Boleta::create(['ciclo_id'=>$ciclo->id,'empresa_id'=>$e->id,'colaborador_id'=>$c->id,'version'=>1,'es_version_vigente'=>true,'regimen_laboral_snapshot'=>'General','sueldo_basico_snapshot'=>3000,'dias_pagados'=>30,'total_ingresos'=>3000,'total_egresos'=>0,'total_aportaciones'=>0,'neto_a_pagar'=>3000,'estado'=>'pagada','snapshot_parametros_version'=>'t','snapshot_reglas_version'=>'t','calculado_at'=>now()]);BoletaConcepto::create(['boleta_id'=>$b->id,'concepto_id'=>$concepto->id,'tipo'=>'ingreso','es_remunerativo_laboral'=>true,'afecta_renta_5ta'=>true,'monto'=>$salarios[$mes]??3000]);$boletas[]=$b;}
        return [$e,$c,$boletas];
    }

    public function test_sueldo_de_octubre_reemplaza_las_provisiones_y_calcula_seis_meses_comerciales():void
    {
        [$e]=$this->crearSemestre(['05'=>2000,'06'=>2000,'07'=>2000,'08'=>2000,'09'=>2000,'10'=>3000]);$r=app(BeneficioSocialService::class)->resumen($e,'cts_noviembre',2026);$f=$r['colaboradores'][0];$this->assertSame(3000.0,$f['sueldo_basico']);$this->assertSame(180,$f['dias_computables']);$this->assertSame(1500.0,$f['neta']);$this->assertSame('calculado',$f['estado']);
    }

    public function test_falta_resuelta_sin_clasificacion_permanece_en_revision():void
    {
        [$e,$c]=$this->crearSemestre(['10'=>3000]);$r=AsistenciaResultadoDiario::create(['empresa_id'=>$e->id,'colaborador_id'=>$c->id,'fecha'=>'2026-06-12','tipo_dia'=>'laborable','estado'=>'falta','procesado_at'=>now()]);AsistenciaIncidencia::create(['empresa_id'=>$e->id,'resultado_diario_id'=>$r->id,'colaborador_id'=>$c->id,'fecha'=>'2026-06-12','tipo'=>'falta','estado'=>'resuelta']);$f=app(BeneficioSocialService::class)->resumen($e,'cts_noviembre',2026)['colaboradores'][0];$this->assertSame('observado',$f['estado']);$this->assertGreaterThan(0,count($f['alertas']));$this->assertSame(1500.0,$f['bruta']);$this->assertSame(1500.0,$f['importe_estimado_sujeto_revision']);$this->assertSame(0.0,$f['importe_aprobable']);
    }
}
