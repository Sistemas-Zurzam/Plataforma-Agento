<?php

namespace App\Modules\Nominas\Services;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Models\BeneficioSocial;
use App\Modules\Nominas\Models\BeneficioSocialDetalle;
use App\Modules\Nominas\Models\BeneficioSocialDeposito;
use App\Modules\Nominas\Models\BeneficioSocialEnvio;
use App\Modules\Nominas\Models\BeneficioSocialHistorico;
use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\BoletaConcepto;
use App\Modules\Nominas\Models\PlanillaComplementariaDetalle;
use App\Modules\Personas\Models\Colaborador;
use App\Modules\Personas\Models\ColaboradorCondicionLaboral;
use App\Modules\Personas\Models\ColaboradorRemuneracion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CTS semestral, calculada con remuneración computable de abril/octubre.
 * TUO CTS (D.S. 001-97-TR), arts. 9, 16-18, 21-22, 25-26;
 * D.S. 013-2013-PRODUCE art. 50 (pequeña empresa: 15 remuneraciones
 * diarias por ano, limite 90). No modifica el flujo de cese.
 *
 * Criterio acordado con RR.HH. (Excel de CTS LIVEX nov-2026):
 *  - Base = sueldo + asignación familiar + 1/6 de la gratificación del semestre
 *    anterior + promedio de horas extra y comisiones (suma del semestre / 6),
 *    estas solo si se percibieron en 3 meses o más. Incluye las pagadas por
 *    planillas complementarias, asignadas al mes de la boleta original.
 *  - Días computables = tiempo de servicio en meses comerciales de 30 días;
 *    las faltas no descuentan días.
 *  - No se depositan: locadores, cesados hasta la fecha límite de depósito
 *    (su CTS va en la liquidación) ni quien tiene menos de 30 días.
 */
class BeneficioSocialService
{
    private const PEQUENA = 'Pequeña Empresa';

    private const LOCADOR = 'Locacion de Servicios';

    /** Remuneraciones variables que se promedian (regla de 3 meses o más). */
    private const VARIABLES_PROMEDIABLES = ['COMISION', 'HE_25', 'HE_35', 'HE_100'];

    public static function rango(string $tipo, int $anio): array
    {
        return match ($tipo) {
            'gratificacion_julio' => ['inicio'=>"$anio-01-01",'fin'=>"$anio-06-30",'vence'=>"$anio-07-15",'codigos'=>['GRATIFICACION_LEGAL','BONIFICACION_EXTRAORDINARIA']],
            'gratificacion_diciembre' => ['inicio'=>"$anio-07-01",'fin'=>"$anio-12-31",'vence'=>"$anio-12-15",'codigos'=>['GRATIFICACION_LEGAL','BONIFICACION_EXTRAORDINARIA']],
            'cts_mayo' => ['inicio'=>Carbon::create($anio-1,11,1)->toDateString(),'fin'=>"$anio-04-30",'referencia'=>"$anio-04-30",'vence'=>"$anio-05-15",'codigos'=>[]],
            'cts_noviembre' => ['inicio'=>"$anio-05-01",'fin'=>"$anio-10-31",'referencia'=>"$anio-10-31",'vence'=>"$anio-11-15",'codigos'=>[]],
            default => throw ValidationException::withMessages(['tipo'=>'Tipo de beneficio no reconocido.']),
        };
    }

    public function resumen(Empresa $empresa, string $tipo, int $anio): array
    {
        $r=self::rango($tipo,$anio);
        $beneficio=BeneficioSocial::where('empresa_id',$empresa->id)->where('tipo',$tipo)->where('anio',$anio)->where('es_version_vigente',true)->with('detalles.colaborador')->first();
        if ($beneficio) {
            $filas=$beneficio->detalles->map(fn($d)=>$this->filaSnapshot($d,$empresa,$beneficio))->values()->all();
            // Último envío de la boleta de CTS por correo de cada fila (columna "Correo").
            $envios=BeneficioSocialEnvio::whereIn('beneficio_social_detalle_id',$beneficio->detalles->pluck('id'))->orderBy('id')->get()->keyBy('beneficio_social_detalle_id');
            $filas=array_map(fn($f)=>[...$f,'envio_correo'=>($e=$envios->get($f['detalle_id']))?['estado'=>$e->estado,'enviado_at'=>$e->enviado_at?->toDateTimeString(),'error'=>$e->error]:null],$filas);
            $pagado=collect($filas)->sum('depositado');
        return ['id'=>$beneficio->id,'colaboradores'=>$filas,'total_colaboradores'=>$beneficio->total_colaboradores,'total_bruto'=>(float)$beneficio->total_bruto,'total_neto'=>(float)$beneficio->total_neto,'total_estimado_sujeto_revision'=>round(collect($filas)->sum('importe_estimado_sujeto_revision'),2),'total_aprobable'=>round(collect($filas)->sum('importe_aprobable'),2),'pendientes_de_pago'=>collect($filas)->whereNotIn('estado',['conciliado'])->count(),'vence'=>$r['vence'],'calculado'=>true,'estado'=>$beneficio->estado,'version'=>$beneficio->version,'calculado_at'=>$beneficio->calculado_at?->toDateTimeString(),'total_depositado'=>round($pagado,2),'origen'=>$beneficio->origen??'calculo','archivo_origen'=>$beneficio->archivo_origen];
        }
        $v=$this->calcularEnVivo($empresa,$tipo,$anio);
        return [...$v,'colaboradores'=>array_map(fn($f)=>[...$f,'estado'=>$f['estado']??'calculado'], $v['colaboradores']),'pendientes_de_pago'=>count($v['colaboradores']),'vence'=>$r['vence'],'calculado'=>false,'estado'=>null,'version'=>null,'calculado_at'=>null];
    }

    public function calcular(Empresa $empresa,string $tipo,int $anio,int $usuarioId): BeneficioSocial
    {
        $v=$this->calcularEnVivo($empresa,$tipo,$anio);
        if (!$v['total_colaboradores']) throw ValidationException::withMessages(['tipo'=>'No hay datos computables en el periodo.']);
        return DB::transaction(function()use($empresa,$tipo,$anio,$usuarioId,$v){
            $prev=BeneficioSocial::where('empresa_id',$empresa->id)->where('tipo',$tipo)->where('anio',$anio)->where('es_version_vigente',true)->lockForUpdate()->first();
            if($prev && ($prev->detalles()->whereHas('depositos')->exists() || $prev->estado==='pagado')) throw ValidationException::withMessages(['tipo'=>'La version vigente esta pagada o tiene depositos; no se puede recalcular. Registre una revision explicita del calculo.']);
            if($prev && $prev->origen==='excel') throw ValidationException::withMessages(['tipo'=>'Esta CTS se cargo desde el Excel de RR.HH. porque Agento no tiene la data de los meses anteriores; recalcularla la reemplazaria. Para corregirla, vuelve a cargar el Excel.']);
            $prev?->update(['es_version_vigente'=>false]);
            $b=BeneficioSocial::create(['empresa_id'=>$empresa->id,'tipo'=>$tipo,'anio'=>$anio,'version'=>($prev?->version??0)+1,'es_version_vigente'=>true,'total_colaboradores'=>$v['total_colaboradores'],'total_bruto'=>$v['total_bruto'],'total_neto'=>$v['total_neto'],'estado'=>'calculado','calculado_por'=>$usuarioId,'calculado_at'=>now()]);
            foreach($v['colaboradores'] as $f){$d=BeneficioSocialDetalle::create(['beneficio_social_id'=>$b->id,'colaborador_id'=>$f['colaborador_id'],'sueldo_basico'=>$f['sueldo_basico'],'meses'=>$f['meses'],'bruta'=>$f['bruta'],'bonificacion_extraordinaria'=>0,'neta'=>$f['neta'],'detalle_calculo'=>$f]);}
            return $b->load('detalles.colaborador');
        });
    }

    public function registrarDeposito(Empresa $empresa,BeneficioSocialDetalle $detalle,int $usuarioId,array $datos): BeneficioSocialDetalle
    {
        $detalle->load('beneficioSocial'); $b=$detalle->beneficioSocial;
        if(!$b || (int)$b->empresa_id!==(int)$empresa->id || !str_starts_with($b->tipo,'cts_')) abort(404);
        if(($detalle->detalle_calculo['estado']??null)==='observado')throw ValidationException::withMessages(['estado'=>'El calculo esta pendiente de revision; no se aceptan depositos hasta resolver sus alertas.']);
        $key=hash('sha256',implode('|',[$detalle->id,$datos['referencia'],$datos['importe'],$datos['fecha_deposito'],$datos['entidad']]));
        DB::transaction(function()use($detalle,$datos,$usuarioId,$key,$b){
            $detalle->newQuery()->whereKey($detalle->id)->lockForUpdate()->firstOrFail();
            if($detalle->depositos()->where('idempotencia',$key)->exists()) return;
            if(BeneficioSocialDeposito::where('referencia',$datos['referencia'])->exists())throw ValidationException::withMessages(['referencia'=>'La referencia de depósito ya fue registrada.']);
            if($b->estado!=='calculado'||!$b->es_version_vigente)throw ValidationException::withMessages(['estado'=>'Solo se reciben depositos contra la version CTS vigente y calculada.']);
            $acumulado=(float)$detalle->depositos()->sum('importe');
            if(round($acumulado+(float)$datos['importe'],2)>round((float)$detalle->neta,2)) throw ValidationException::withMessages(['importe'=>'El depósito supera el saldo calculado.']);
            $detalle->depositos()->create(['fecha_deposito'=>$datos['fecha_deposito'],'importe'=>$datos['importe'],'entidad'=>$datos['entidad'],'referencia'=>$datos['referencia'],'constancia'=>$datos['constancia']??null,'idempotencia'=>$key,'registrado_por'=>$usuarioId]);
            $detalle->refresh(); $detalle->estado_deposito=round((float)$detalle->depositos()->sum('importe'),2)>=(float)$detalle->neta?'conciliado':(round((float)$detalle->depositos()->sum('importe'),2)>0?'depositado_parcial':'pendiente_deposito'); $detalle->save();
        });
        return $detalle->fresh('depositos');
    }

    private function filaSnapshot(BeneficioSocialDetalle $d,Empresa $empresa,BeneficioSocial $b):array
    {
        $f=$d->detalle_calculo??[]; $depositado=(float)$d->depositos()->sum('importe');
        $estadoDeposito=$depositado>=(float)$d->neta?'conciliado':($depositado>0?'depositado_parcial':($f['estado']??$d->estado_deposito??'pendiente_deposito'));
        $aprobable=($f['estado']??null)==='observado'?0.0:(float)$d->neta;
        return [...$f,'detalle_id'=>$d->id,'colaborador_id'=>$d->colaborador_id,'colaborador'=>trim(($d->colaborador?->nombres??'').' '.($d->colaborador?->apellidos??'')),'legajo'=>$d->colaborador?->legajo,'empresa'=>$empresa->nombre_comercial,'sueldo_basico'=>(float)$d->sueldo_basico,'meses'=>(int)$d->meses,'bruta'=>(float)$d->bruta,'neta'=>(float)$d->neta,'importe_aprobable'=>$aprobable,'depositado'=>round($depositado,2),'diferencia'=>round(max(0,$aprobable-$depositado),2),'estado'=>($f['estado']??null)==='observado'?'observado':$estadoDeposito,'estado_lote'=>$b->estado,'depositos'=>$d->depositos()->get()->map(fn($x)=>['fecha'=>$x->fecha_deposito?->toDateString(),'importe'=>(float)$x->importe,'entidad'=>$x->entidad,'referencia'=>$x->referencia,'constancia'=>$x->constancia])->all()];
    }

    private function calcularEnVivo(Empresa $empresa,string $tipo,int $anio):array
    {
        $r=self::rango($tipo,$anio); if(!str_starts_with($tipo,'cts_')) return $this->calcularGratificacion($empresa,$tipo,$anio,$r);
        $fecha=Carbon::parse($r['referencia']); $desde=Carbon::parse($r['inicio']); $hasta=Carbon::parse($r['fin']);
        $boletas=Boleta::where('empresa_id',$empresa->id)->where('es_version_vigente',true)->whereHas('ciclo',fn($q)=>$q->whereDate('fecha_inicio','>=',$desde)->whereDate('fecha_fin','<=',$hasta))->with('colaborador','conceptos.concepto','ciclo')->get();
        $ids=$boletas->pluck('colaborador_id')->unique(); $filas=[];
        foreach($ids as $id){$c=Colaborador::withTrashed()->find($id); if(!$c)continue; if($c->fecha_cese&&Carbon::parse($c->fecha_cese)->lte(Carbon::parse($r['vence'])))continue; $v=$boletas->where('colaborador_id',$id); $ingreso=Carbon::parse($c->fecha_ingreso); $ini=$ingreso->max($desde); $fin=($c->fecha_cese?Carbon::parse($c->fecha_cese):$hasta)->min($hasta); if($fin->lt($ini))continue;
            $condiciones=ColaboradorCondicionLaboral::where('colaborador_id',$id)->whereDate('vigencia_desde','<=',$fecha)->orderByDesc('vigencia_desde')->get();
            $regimenes=$condiciones->groupBy(fn($x)=>$x->vigencia_desde->toDateString())->map(fn($xs)=>$xs->pluck('regimen_laboral')->unique()->filter()->values());
            $ambigua=$regimenes->contains(fn($xs)=>$xs->count()>1);
            $regimen=ColaboradorCondicionLaboral::vigenteEn($id,$fecha->toDateString())?->regimen_laboral;
            if($regimenes->isEmpty())$regimen=null;
            if($regimen==='Pequena Empresa')$regimen=self::PEQUENA;
            if($regimen===self::LOCADOR||(!$regimen&&$c->regimen_laboral===self::LOCADOR))continue;
            $mype= $regimen===self::PEQUENA && $empresa->inscrita_remype && $empresa->fecha_inscripcion_remype && Carbon::parse($empresa->fecha_inscripcion_remype)->lte($ini);
            $alertas=[]; if(!$regimen)$alertas[]='Regimen laboral faltante: el historial por fecha de referencia no determina el regimen'; if($ambigua)$alertas[]='Mas de un regimen con igual vigencia; requiere resolver manualmente'; if(in_array($regimen,['Micro Empresa',self::PEQUENA]) && !$mype)$alertas[]='Sustento REMYPE no acredita regimen especial a la fecha de inicio del vinculo';
            $tope=$mype?$this->acumuladoPequena($empresa,$c,$ingreso,$desde,$hasta):['acumulado_monto'=>0.0,'completo'=>true,'faltante'=>null]; if(!$tope['completo'])$alertas[]='Pendiente: '.$tope['faltante'];
            $base=[]; $rem=ColaboradorRemuneracion::where('colaborador_id',$id)->whereDate('vigencia_desde','<=',$fecha)->orderByDesc('vigencia_desde')->first();
            if($rem){$base['SUELDO_BASICO']=(float)$rem->salario;if((float)$rem->asignacion_familiar>0)$base['ASIGNACION_FAMILIAR']=(float)$rem->asignacion_familiar;}else{$alertas[]='Sin historial salarial en fecha de referencia; no se determina la base';}
            $refBoleta=$v->first(fn($b)=>$b->ciclo->fecha_inicio->lte($fecha)&&$b->ciclo->fecha_fin->gte($fecha));
            if($refBoleta){foreach($refBoleta->conceptos as $l){$codigo=$l->concepto?->codigo;if(!$codigo||in_array($codigo,['SUELDO_BASICO','ASIGNACION_FAMILIAR','GRATIFICACION_LEGAL','BONIFICACION_EXTRAORDINARIA','CTS_PROVISION']))continue;if($l->concepto?->afecta_cts && !in_array($codigo,['COMISION','HE_25','HE_35','HE_100','BONIFICACION']))$alertas[]="Naturaleza CTS de {$codigo} no configurada; importe pendiente de clasificacion";}}
            else{$alertas[]='No existe boleta del mes de referencia para validar conceptos adicionales y asistencia; base sujeta a revision';}
            $variables=$this->variables($v,$this->variablesComplementarias($v)); foreach($variables as $codigo=>$dato){if($dato['pendiente'])$alertas[]="{$codigo}: {$dato['alerta']}";$base[$codigo]=$dato['incluido'];}
            $gratsBeneficios=BeneficioSocial::where('empresa_id',$empresa->id)->where('tipo',$tipo==='cts_noviembre'?'gratificacion_julio':'gratificacion_diciembre')->where('anio',$tipo==='cts_noviembre'?$anio:$anio-1)->where('estado','pagado')->whereHas('detalles',fn($q)=>$q->where('colaborador_id',$id))->with('detalles')->get()->flatMap->detalles->where('colaborador_id',$id);
            $gratsHist=BeneficioSocialHistorico::where('empresa_id',$empresa->id)->where('colaborador_id',$id)->where('tipo',$tipo==='cts_noviembre'?'gratificacion_julio':'gratificacion_diciembre')->whereIn('estado',['pagado','depositado'])->vigentes()->get();
            $gratsBoleta=BoletaConcepto::whereHas('concepto',fn($q)=>$q->where('codigo','GRATIFICACION_LEGAL'))->whereHas('boleta',fn($q)=>$q->where('empresa_id',$empresa->id)->where('colaborador_id',$id)->where('estado','pagada')->whereHas('ciclo',fn($cq)=>$cq->whereDate('fecha_fin','>=',($tipo==='cts_noviembre'?"$anio-07-01":($anio-1).'-12-01'))->whereDate('fecha_fin','<=',($tipo==='cts_noviembre'?"$anio-07-31":($anio-1).'-12-31'))))->sum('monto');
            $fuentes=array_filter([['fuente'=>'beneficio_interno','importe'=>(float)$gratsBeneficios->sum('bruta'),'id'=>$gratsBeneficios->first()?->id],['fuente'=>'historial','importe'=>(float)$gratsHist->sum('importe_pagado'),'id'=>$gratsHist->first()?->id],['fuente'=>'boleta_pagada','importe'=>(float)$gratsBoleta,'id'=>null]],fn($x)=>$x['importe']>0);
            if(count($fuentes)>1){$alertas[]='Gratificacion duplicada o discrepante entre registros pagados; sexto pendiente de conciliar';$grats=0;}else{$grats=(float)(reset($fuentes)['importe']??0);}
            $ultimoMesGrati=Carbon::parse($tipo==='cts_noviembre'?"$anio-06-01":($anio-1).'-12-01');
            if(!$fuentes&&$ingreso->lte($ultimoMesGrati))$alertas[]=($tipo==='cts_noviembre'?"Gratificacion de julio $anio":'Gratificacion de diciembre '.($anio-1)).' no registrada como pagada: el 1/6 queda en 0. Registrala o importala como antecedente.';
            $baseTotal=round(array_sum($base)+round((float)$grats/6,2),2); $dias=$this->diasComerciales($ini,$fin); if($dias<30)continue; $meses=intdiv($dias,30); $diasResto=$dias%30;
            $tasa=$regimen===self::PEQUENA&&$mype?0.5:($regimen==='Micro Empresa'&&$empresa->inscrita_remype?0:1); $monto=$dias>=30?round($baseTotal*$dias/360*$tasa,2):0;
            if($mype){if(!$tope['completo'])$monto=0;else{$consumido=(float)$tope['remuneraciones_diarias_consumidas'];$disponible=max(0,90-$consumido);$monto=min($monto,round($baseTotal/30*$disponible,2));}}
            $historicos=BeneficioSocialHistorico::where('empresa_id',$empresa->id)->where('colaborador_id',$id)->where('tipo',$tipo)->where('anio',$anio)->whereDate('fecha_ingreso_vinculo',$ingreso->toDateString())->vigentes()->get();
            $requiereRevision=count($alertas)>0;
            $filas[]=['colaborador_id'=>$id,'colaborador'=>trim($c->nombres.' '.$c->apellidos),'legajo'=>$c->legajo,'empresa'=>$empresa->nombre_comercial,'vinculo'=>['fecha_ingreso'=>$ingreso->toDateString(),'fecha_fin'=>$c->fecha_cese?->toDateString()],'regimen'=>$regimen,'vigencia_regimen'=>['desde'=>$ini->toDateString(),'hasta'=>$fin->toDateString()],'periodo'=>['inicio'=>$desde->toDateString(),'fin'=>$hasta->toDateString(),'referencia'=>$fecha->toDateString()],'conceptos'=>$base,'gratificacion_legal_percibida'=>round((float)$grats,2),'gratificacion_evidencia'=>$gratsHist->pluck('id')->all(),'gratificacion_sexto'=>round((float)$grats/6,2),'variables'=>$variables,'meses'=>$meses,'dias'=>$diasResto,'dias_computables'=>$dias,'ausencias'=>[],'divisor'=>360,'tasa'=>$tasa,'formula'=>"({$baseTotal}/360) x {$dias} x {$tasa}",'bruta'=>round($monto,2),'neta'=>round($monto,2),'importe_estimado_sujeto_revision'=>$requiereRevision?round($monto,2):null,'importe_aprobable'=>$requiereRevision?0.0:round($monto,2),'sueldo_basico'=>(float)($base['SUELDO_BASICO']??0),'base_diaria_tope_mype'=>round(((float)($base['SUELDO_BASICO']??0))/30,4),'tope'=>['remuneraciones_diarias_consumidas'=>$tope['remuneraciones_diarias_consumidas']??null,'completo'=>$tope['completo'],'saldo_disponible'=>$mype&&$tope['completo']?max(0,90-($tope['remuneraciones_diarias_consumidas']??0)):null,'periodos_cubiertos'=>$tope['periodos_con_sustento']??null,'periodos_esperados'=>$tope['periodos_esperados']??null,'dato_faltante'=>$tope['faltante']??null],'depositos_historicos'=>$historicos->map(fn($h)=>['id'=>$h->id,'estado'=>$h->estado,'importe_pagado'=>(float)$h->importe_pagado,'referencia'=>$h->referencia_externa])->all(),'alertas'=>$alertas,'estado'=>$requiereRevision?'observado':'calculado'];
        }
        return ['colaboradores'=>$filas,'total_colaboradores'=>count($filas),'total_bruto'=>round(collect($filas)->sum('bruta'),2),'total_neto'=>round(collect($filas)->sum('neta'),2),'total_estimado_sujeto_revision'=>round(collect($filas)->sum('importe_estimado_sujeto_revision'),2),'total_aprobable'=>round(collect($filas)->sum('importe_aprobable'),2)];
    }

    private function calcularGratificacion(Empresa $empresa,string $tipo,int $anio,array $r):array
    {
        $lineas=BoletaConcepto::whereHas('concepto',fn($q)=>$q->whereIn('codigo',$r['codigos']))->whereHas('boleta',fn($q)=>$q->where('empresa_id',$empresa->id)->where('es_version_vigente',true)->whereHas('ciclo',fn($q2)=>$q2->whereDate('fecha_inicio','>=',$r['inicio'])->whereDate('fecha_fin','<=',$r['fin'])))->with('boleta.colaborador','concepto')->get();
        $rows=$lineas->groupBy('boleta.colaborador_id')->map(function($ls)use($empresa){$b=$ls->first()->boleta;$br=$ls->where('concepto.codigo','GRATIFICACION_LEGAL')->sum('monto');$bon=$ls->where('concepto.codigo','BONIFICACION_EXTRAORDINARIA')->sum('monto');return ['colaborador_id'=>$b->colaborador_id,'colaborador'=>trim($b->colaborador->nombres.' '.$b->colaborador->apellidos),'legajo'=>$b->colaborador->legajo,'empresa'=>$empresa->nombre_comercial,'sueldo_basico'=>(float)$b->sueldo_basico_snapshot,'meses'=>$ls->pluck('boleta_id')->unique()->count(),'bruta'=>round($br,2),'bonificacion_extraordinaria'=>round($bon,2),'neta'=>round($br+$bon,2),'estado'=>'calculado'];})->values();return ['colaboradores'=>$rows->all(),'total_colaboradores'=>$rows->count(),'total_bruto'=>round($rows->sum('bruta'),2),'total_neto'=>round($rows->sum('neta'),2)];
    }

    /** @param  array<string, array<string, float>>  $adicionales  Importes por código y mes ('Y-m') pagados fuera de la boleta (complementarias). */
    private function variables($boletas,array $adicionales=[]):array
    {
        $out=[];$grupos=[];foreach($boletas as $b)foreach($b->conceptos as $l){$codigo=$l->concepto?->codigo;if(!$codigo||in_array($codigo,['SUELDO_BASICO','ASIGNACION_FAMILIAR','GRATIFICACION_LEGAL','CTS_PROVISION','BONIFICACION_EXTRAORDINARIA'])||!$l->concepto?->afecta_cts)continue;$mes=$b->ciclo->fecha_fin->format('Y-m');$grupos[$codigo][$mes]=($grupos[$codigo][$mes]??0)+(float)$l->monto;}
        foreach($adicionales as $codigo=>$porMes)foreach($porMes as $mes=>$monto)$grupos[$codigo][$mes]=($grupos[$codigo][$mes]??0)+(float)$monto;
        foreach($grupos as $codigo=>$porMes){ksort($porMes);$noCero=array_filter($porMes,fn($x)=>$x!=0);$meses=count($noCero);$esVariable=in_array($codigo,self::VARIABLES_PROMEDIABLES);$califica=$meses>=3;$incluido=$califica?round(array_sum($noCero)/6,2):0.0;
            // HE y comisiones: suma del semestre / 6 si se percibieron en 3 meses o más (criterio de RR.HH.). Otros conceptos afectos siguen pendientes de clasificar.
            $out[$codigo]=['naturaleza'=>$esVariable?'variable_promedio_semestral':'requiere_clasificacion','meses_percibidos'=>$meses,'meses_califican'=>$califica?$meses:0,'importes_mensuales'=>$porMes,'ventana'=>'semestre CTS','divisor'=>6,'incluido'=>$incluido,'pendiente'=>!$esVariable,'alerta'=>$esVariable?'':($codigo==='BONIFICACION'?'Falta naturaleza y periodicidad del bono':(!$califica?'No alcanza 3 meses de percepcion':'Falta clasificar el concepto para CTS'))];}return $out;
    }

    /**
     * Comisiones y horas extra pagadas por planillas complementarias, por mes
     * de la boleta original. El snapshot de una complementaria es acumulativo
     * (parte de la última pagada), así que por boleta se toma solo la última
     * complementaria pagada y sus líneas agregadas (con agregado_por).
     *
     * @return array<string, array<string, float>>
     */
    private function variablesComplementarias($boletas):array
    {
        $out=[];$porId=$boletas->keyBy('id');
        $detalles=PlanillaComplementariaDetalle::whereIn('boleta_original_id',$porId->keys())->whereHas('complementaria',fn($q)=>$q->where('estado','pagada'))->orderBy('id')->get()->groupBy('boleta_original_id');
        foreach($detalles as $boletaId=>$ds){$mes=$porId[$boletaId]->ciclo->fecha_fin->format('Y-m');
            foreach($ds->last()->calculo_snapshot['ingresos']??[] as $l){$codigo=$l['codigo']??null;if(!isset($l['agregado_por'])||!in_array($codigo,self::VARIABLES_PROMEDIABLES,true))continue;$out[$codigo][$mes]=($out[$codigo][$mes]??0)+(float)($l['monto']??0);}}
        return $out;
    }
    private function diasComerciales(Carbon $desde,Carbon $hasta):int
    { $total=0;$mes=$desde->copy()->startOfMonth();while($mes->lte($hasta)){$ini=$mes->copy()->max($desde);$fin=$mes->copy()->endOfMonth()->min($hasta);$mesCompleto=$ini->day===1&&$fin->day===$fin->daysInMonth;if($mesCompleto)$total+=30;else{$diaIni=min(30,$ini->day);$diaFin=min(30,$fin->day);$total+=max(0,$diaFin-$diaIni+1);}$mes->addMonth();}return $total;}
    private function acumuladoPequena(Empresa $e,Colaborador $c,Carbon $ingreso,Carbon $desde,Carbon $hasta):array
    {
        if($ingreso->gte($desde)||$ingreso->copy()->addYears(6)->gt($hasta)) return ['acumulado_monto'=>0.0,'remuneraciones_diarias_consumidas'=>0.0,'completo'=>true,'periodos_con_sustento'=>0,'periodos_esperados'=>0,'faltante'=>null];
        $hist=BeneficioSocialHistorico::where('empresa_id',$e->id)->where('colaborador_id',$c->id)->whereDate('fecha_ingreso_vinculo',$ingreso->toDateString())->whereIn('estado',['pagado','depositado'])->vigentes()->get();
        if($hist->isNotEmpty()) return ['acumulado_monto'=>null,'remuneraciones_diarias_consumidas'=>null,'completo'=>false,'periodos_con_sustento'=>0,'periodos_esperados'=>null,'faltante'=>'Antecedente histórico sin base diaria del período y comprobante individual para convertirlo a remuneraciones diarias'];
        $beneficios=BeneficioSocial::where('empresa_id',$e->id)->where('tipo','like','cts_%')->where('es_version_vigente',true)->whereHas('detalles',fn($q)=>$q->where('colaborador_id',$c->id))->with('detalles.depositos')->get(); $presentes=[]; $consumido=0.0;
        foreach($beneficios as $b){$d=$b->detalles->firstWhere('colaborador_id',$c->id);if(!$d||$d->depositos->isEmpty())continue;$diaria=(float)(($d->detalle_calculo??[])['base_diaria_tope_mype']??0);if($diaria<=0)return ['acumulado_monto'=>null,'remuneraciones_diarias_consumidas'=>null,'completo'=>false,'periodos_con_sustento'=>count($presentes),'periodos_esperados'=>null,'faltante'=>"Falta base diaria histórica para {$b->tipo} {$b->anio}"];$consumido+=(float)$d->depositos->sum('importe')/$diaria;$presentes[]=$b->tipo.'-'.$b->anio;}
        $esperadosPeriodos=[];$cursor=$ingreso->copy()->startOfMonth();while($cursor->lt($desde)){if($cursor->month<=4){$esperadosPeriodos[]='cts_mayo-'.$cursor->year;$cursor->setMonth(5)->startOfMonth();}elseif($cursor->month<=10){$esperadosPeriodos[]='cts_noviembre-'.$cursor->year;$cursor->setMonth(11)->startOfMonth();}else{$esperadosPeriodos[]='cts_mayo-'.($cursor->year+1);$cursor->addYear()->setMonth(5)->startOfMonth();}}$esperados=count(array_unique($esperadosPeriodos));$completo=count(array_diff(array_unique($esperadosPeriodos),$presentes))===0;
        return ['acumulado_monto'=>round($consumido,2),'remuneraciones_diarias_consumidas'=>round($consumido,4),'completo'=>$completo,'periodos_con_sustento'=>count($presentes),'periodos_esperados'=>$esperados,'faltante'=>$completo?null:'Falta cobertura completa, depósito verificable y base diaria histórica desde el inicio del vínculo'];
    }
}
