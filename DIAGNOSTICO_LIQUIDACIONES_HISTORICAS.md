# Diagnóstico técnico — Liquidaciones de cese y dependencia de historial anterior a agosto 2026

> Informe de solo lectura. No se modificó código, no se crearon ni aplicaron migraciones, no se ejecutaron seeders, comandos, cálculos, cierres, tests ni commits. Todas las afirmaciones citan archivo y línea del código real inspeccionado en el repositorio (`agento-backend/`). Cuando no se localizó algo, se indica expresamente.

## 1. Resumen ejecutivo

Agento ya tiene un módulo completo y en producción para liquidar ceses (`LiquidacionCeseService`, con controlador, modelo, tests y flujo calculada→aprobada→pagada→anulada). El motor de liquidación reutiliza el mismo motor de cálculo de planilla mensual (`CalcularBoletaColaborador` + `PlanillaDependienteCalculator`), no una fórmula paralela.

El problema no es la ausencia de un módulo de liquidación — es que **el sistema no tiene datos anteriores al 13-ago-2026** (primera migración de `empresas`), y tres de las cuatro fórmulas de la liquidación dependen, en distinto grado, de información que solo existe si fue registrada dentro de Agento:

- **CTS trunca**: su base legal exige sumar 1/6 de la *última gratificación pagada*. Esa búsqueda solo mira `beneficio_social_detalles`, que a su vez solo se llena sumando `boleta_conceptos` ya calculados en Agento. Ninguna gratificación pagada antes de agosto puede aparecer ahí.
- **Vacaciones truncas**: resta días gozados leyendo `asistencia_permisos` (tipo vacaciones), que tampoco existe antes de agosto. Si el colaborador ya gozó vacaciones antes de esa fecha, ese consumo no se descuenta.
- **Remuneración de cese / Gratificación trunca**: ambas están acotadas al mes/semestre en curso — no miran hacia atrás de su propia ventana, así que **no hay riesgo estructural de duplicar agosto ni semestres ya cerrados**.

El propio código ya contiene el mecanismo pensado para este escenario: `vacacion_movimientos` con `tipo = 'devengo_inicial'`, documentado explícitamente en el controlador como "carga de saldo al migrar de otro sistema" (sección 7). No existe un mecanismo equivalente para el sexto de gratificación de CTS ni para las gratificaciones/CTS históricas en general — ese es el hueco real a diseñar.

Se encontró además un riesgo confirmado y no relacionado con el Excel: la recontratación de un colaborador ya cesado reutiliza el mismo `colaborador_id` y, al volver a cesarlo, el servicio de liquidación **desactiva silenciosamente la versión vigente de la liquidación anterior** (de un vínculo laboral distinto y ya pagado) — ver sección 3, pregunta 11.

## 2. Alcance revisado

Se inspeccionaron los módulos `Nominas`, `Personas` y `Asistencia` del backend Laravel (`agento-backend/app/Modules/...`), sus migraciones, rutas API, y las pruebas automatizadas existentes. No se inspeccionó el frontend (no es relevante para el diagnóstico de datos/fórmulas). No se ejecutó ningún comando; toda observación proviene de lectura estática de código y de `git log`/`git diff --stat` (también de solo lectura).

## 3. Archivos inspeccionados

**Application / Domain / Services (Nominas):**
- `app/Modules/Nominas/Services/LiquidacionCeseService.php`
- `app/Modules/Nominas/Services/BeneficioSocialService.php`
- `app/Modules/Nominas/Services/BoletaService.php`
- `app/Modules/Nominas/Services/CicloRemunerativoService.php`
- `app/Modules/Nominas/Services/PlanillaComplementariaService.php`
- `app/Modules/Nominas/Application/CalcularBoletaColaborador.php`
- `app/Modules/Nominas/Application/VerificarConsistenciaAsistenciaCiclo.php`
- `app/Modules/Nominas/Application/NotificarCambioAsistenciaCiclo.php`
- `app/Modules/Nominas/Domain/RegimenCalculator.php`, `RegimenCalculatorFactory.php`, `PlanillaDependienteCalculator.php`
- `app/Modules/Nominas/Support/ParametrosVigentesResolver.php`
- `app/Modules/Nominas/Jobs/CalcularPlanillaJob.php`

**Modelos (Nominas):** `LiquidacionCese.php`, `LiquidacionCeseConcepto.php`, `BeneficioSocial.php`, `BeneficioSocialDetalle.php`, `Boleta.php`, `BoletaConcepto.php`, `ConceptoRemuneracion.php`, `CicloRemunerativo.php`, `VacacionMovimiento.php`.

**Controladores/Requests (Nominas):** `LiquidacionCeseController.php`, `BeneficioSocialController.php` (solo referenciado por rutas, no fue necesario leerlo completo), `VacacionMovimientoController.php`, `StoreVacacionMovimientoRequest.php`.

**Personas:** `Colaborador.php`, `ColaboradorRemuneracion.php`, `Services/ColaboradorService.php` (método `cesar()`/`restaurar()`), `Http/Controllers/ColaboradorController.php` (métodos `cesar()`, `previsualizarLiquidacionCese()`).

**Asistencia:** `AsistenciaPermiso.php`, `Services/AsistenciaOperacionService.php` (método `vacacionesTomadas()`), `Models/AsistenciaImportacion.php` (referencia de patrón de importación con trazabilidad, no relacionado con Nóminas).

**Migraciones clave:** `2026_08_14_000024_create_colaboradores_table.php`, `2026_08_17_000030_add_termination_and_soft_deletes_to_colaboradores_table.php`, `2026_08_27_000061_restringir_borrado_colaborador_en_remuneraciones_y_boletas.php`, `2026_08_31_000104_crear_liquidaciones_cese.php`, `2026_08_31_000105_endurecer_liquidaciones_cese.php` (crea `vacacion_movimientos`), `2026_08_17_000032_create_asistencia_core_tables.php` (patrón `asistencia_importaciones`), `2026_09_04_000115_agregar_requiere_recalculo_a_ciclos_remunerativos.php`.

**Rutas:** `routes/api.php` (bloques de `colaboradores/.../liquidacion-cese`, `vacacion-movimientos`, `beneficios-sociales`, `liquidaciones-cese`).

**Pruebas encontradas:** `tests/Feature/LiquidacionCeseServiceTest.php`, `tests/Feature/LiquidacionCeseControllerTest.php`, `tests/Feature/Modules/Nominas/CicloRemunerativoConcurrenciaTest.php`, `tests/Feature/Modules/Nominas/NotificarCambioAsistenciaCicloTest.php`, `tests/Feature/Modules/Nominas/VerificarConsistenciaAsistenciaCicloTest.php`.

**No localizados (búsqueda explícita, sin resultados):**
- Policies de Laravel (`find app -iname "*Policy*"` → vacío). La autorización se resuelve con middleware de string (`permiso:nominas.pagar`, etc.), no con `Illuminate\Auth\Access\Policy`.
- Comandos de consola en `app/Console` (directorio sin archivos `.php` propios de dominio).
- Clases de `Events`/`Listeners` de dominio (los únicos matches de "Job"/"Listener" fueron los dos Jobs de cola ya listados).
- Cualquier exportador o generador de documento (PDF/Excel) de `LiquidacionCese` — existen exportadores para AFPnet, BBVA NetCash, PLAME, Telecrédito y "Planilla Pagada" (`Infrastructure/PlanillaPagada/Export/PlanillaPagadaExcelExporter.php`), pero ninguno bajo `Infrastructure/LiquidacionCese` ni equivalente.
- Pruebas para `BeneficioSocialService` (no se encontró ningún archivo de test que lo mencione).
- Cualquier campo o tabla llamada `es_historico`, `saldo_inicial`, `pagado_externamente`, `referencia_externa` o `fuente` en todo `app/Modules` y `database/migrations` (búsqueda `grep -rniE` sin resultados). Sí existe `importacion_id`, pero únicamente en el módulo de Asistencia (marcaciones biométricas), no en Nóminas.

## 4. Arquitectura actual

```
Colaborador (Personas)
   fecha_ingreso, fecha_cese, regimen_laboral, activo
        │
ColaboradorRemuneracion — historial de sueldo por vigencia_desde (nunca se sobrescribe)
        │
AsistenciaResultadoDiario / AsistenciaPermiso / AsistenciaHoraExtra / AsistenciaIncidencia
   (procesados día a día; solo existen desde que Asistencia opera en Agento)
        │
CicloRemunerativo (mensual)          abierto → calculado → cerrado → pagado
        │                                          ↑ reabierto (desde cerrado, si nada pagado)
CalcularBoletaColaborador → Boleta + BoletaConcepto   calculada → aprobada → pagada
        │
BeneficioSocial + BeneficioSocialDetalle   (gratificación/CTS íntegra semestral)
        │
PlanillaComplementaria + PlanillaComplementariaDetalle  (ajusta un ciclo YA pagado sin duplicar)
        │
LiquidacionCese + LiquidacionCeseConcepto   calculada → aprobada → pagada  (o anulada)
```

Tabla por paso del flujo:

| Paso | Clase / método | Tabla | Estados | Entrada | Salida | Efectos secundarios | Transacción |
|---|---|---|---|---|---|---|---|
| Crear ciclo | `CicloRemunerativoService::crear()` [`app/Modules/Nominas/Services/CicloRemunerativoService.php:62-81`] | `ciclos_remunerativos` | `abierto` | fechas, empresa | ciclo nuevo | ninguno | `DB::transaction` con `lockForUpdate` en `empresas` |
| Calcular planilla | `BoletaService::calcularPlanilla()` [`BoletaService.php:191-223`] → `calcularBoletaColaborador()` [`BoletaService.php:262-385`] | `boletas`, `boleta_conceptos` | ciclo pasa a `calculado`; boleta nace `calculada` | colaboradores elegibles del ciclo | boleta + líneas de ingreso/egreso/aportación | Apaga versión anterior de la boleta (`es_version_vigente=false`, nunca se borra) [`BoletaService.php:297-299`] | `DB::transaction` **por colaborador** [`BoletaService.php:264`] |
| Aprobar boleta | `BoletaService::aprobar()` [`BoletaService.php:418-431`] | `boletas` | `calculada`→`aprobada` | — | — | ninguno | sin transacción explícita (update simple) |
| Cerrar ciclo | `CicloRemunerativoService::cerrar()` [`CicloRemunerativoService.php:152-177`] | `ciclos_remunerativos`, `boleta_datos_pago` | exige boletas vigentes todas `aprobada`/`pagada`; ciclo pasa a `cerrado` | — | snapshot bancario congelado (`congelarDatosPago`, `firstOrCreate` idempotente) [`CicloRemunerativoService.php:211-236`] | congela cuenta bancaria de cada boleta vigente | sin transacción explícita |
| Marcar boleta pagada | `BoletaService::marcarPagada()` [`BoletaService.php:472-490`] | `boletas` | `aprobada`→`pagada`, exige `referencia_pago` | referencia de pago | — | ninguno | sin transacción explícita |
| Marcar ciclo pagado | `CicloRemunerativoService::marcarPagado()` [`CicloRemunerativoService.php:289-327`] | `ciclos_remunerativos` | exige `cerrado`, todas las boletas vigentes `pagada`, y `requiere_recalculo=false`; pasa a `pagado` | — | — | ninguno | `DB::transaction` + `lockForUpdate` sobre el ciclo (cierra condición de carrera con `requiere_recalculo`) |
| Calcular beneficio social (gratificación/CTS íntegra) | `BeneficioSocialService::calcular()` [`BeneficioSocialService.php:123-169`] | `beneficios_sociales`, `beneficio_social_detalles` | `calculado`→`pagado` | tipo (`gratificacion_julio`/`diciembre`, `cts_mayo`/`noviembre`), año | snapshot versionado | Apaga versión anterior [`BeneficioSocialService.php:139`] | `DB::transaction` |
| Previsualizar liquidación | `LiquidacionCeseService::previsualizar()` [`LiquidacionCeseService.php:32-155`] | (solo lectura) | — | colaborador, fecha de cese, selección de conceptos a incluir | conceptos + alertas, **sin persistir** | ninguno | ninguna (es de solo lectura) |
| Confirmar cese + liquidación | `ColaboradorController::cesar()` [`app/Modules/Personas/Http/Controllers/ColaboradorController.php:328-345`] → `ColaboradorService::cesar()` [`app/Modules/Personas/Services/ColaboradorService.php:520-557`] → `LiquidacionCeseService::guardar()` [`LiquidacionCeseService.php:157-183`] | `liquidaciones_cese`, `liquidacion_cese_conceptos`, `colaboradores`, `colaborador_horario_asignaciones` | liquidación nace `calculada`; colaborador pasa `activo=false` | fecha de cese, motivo, selección de conceptos | liquidación + colaborador inactivado en la MISMA operación | Cierra vigencias de horario del colaborador [`ColaboradorService.php:543-545`] | **Una sola** `DB::transaction`, con `lockForUpdate` sobre el `Colaborador` [`ColaboradorService.php:530`] — la liquidación y la inactivación son atómicas |
| Aprobar liquidación | `LiquidacionCeseController::aprobar()` [`LiquidacionCeseController.php:43-51`] | `liquidaciones_cese` | `calculada`→`aprobada` | — | — | ninguno | sin transacción explícita |
| Pagar liquidación | `LiquidacionCeseController::pagar()` [`LiquidacionCeseController.php:53-62`] | `liquidaciones_cese` | `aprobada`→`pagada`, exige `referencia_pago` | referencia de pago | — | ninguno | sin transacción explícita |
| Anular y revertir | `LiquidacionCeseController::anularYRevertir()` [`LiquidacionCeseController.php:64-84`] | `liquidaciones_cese`, `colaboradores`, `colaborador_horario_asignaciones` | bloqueado si `pagada` o `anulada`; pasa a `anulada` | motivo | colaborador reactivado (`activo=true`, `fecha_cese=null`) | Reabre la asignación de horario cerrada en el cese | `DB::transaction` + `lockForUpdate` sobre liquidación y colaborador |

No existen Jobs, Listeners ni Events de dominio para ningún paso posterior a la liquidación (no hay notificación automática, generación de PDF, ni integración contable). El único Job relacionado con Nóminas es `CalcularPlanillaJob` [`app/Modules/Nominas/Jobs/CalcularPlanillaJob.php`], que solo encola el cálculo de planilla mensual (`BoletaService::calcularPlanilla()`), no la liquidación de cese.

## 5. Flujo de liquidación (detalle paso a paso)

1. **Previsualizar** (`GET /colaboradores/{colaborador}/liquidacion-cese/previsualizar`, [`routes/api.php:159`]) — solo lectura, valida régimen y remuneración vigente, calcula los 4 conceptos y regresa alertas. No persiste nada.
2. **Confirmar cese** (`PATCH` a través de `ColaboradorController::cesar()`) — dentro de UNA transacción: bloquea la fila del colaborador, valida que no esté ya cesado, llama a `LiquidacionCeseService::guardar()` (que internamente vuelve a llamar a `previsualizar()` para congelar el snapshot — [`LiquidacionCeseService.php:159`]), y solo después inactiva al colaborador. Si `guardar()` lanza una excepción (p. ej. remuneración inexistente, gratificación del semestre ya pagada, boleta de agosto solapada), toda la transacción hace rollback y el colaborador **sigue activo**.
3. **Aprobar / Pagar / Anular** — cambios de estado simples, cada uno auditado con `*_por`/`*_at`.

## 6. Fórmulas encontradas

| Concepto | Método | Datos utilizados | Periodo consultado | Fórmula encontrada | Tablas consultadas | Riesgo por falta de historial |
|---|---|---|---|---|---|---|
| Remuneración pendiente hasta el cese | `LiquidacionCeseService::previsualizar()` [`LiquidacionCeseService.php:60-88`] | `ColaboradorRemuneracion` vigente, resultado de `CalcularBoletaColaborador::calcular()` del mes de cese | Del día 1 del mes de cese (o fecha de ingreso si es posterior) hasta la fecha de cese | `(sueldo/30) × días pagados del mes de cese`, descontando días ya pagados en boleta vigente | `colaborador_remuneraciones`, `boletas` (para detectar solape), motor de planilla completo | **Bajo.** Ya valida explícitamente que no exista una boleta pagada solapada con ese mes [`LiquidacionCeseService.php:62-68`] — lanza excepción si la hay. No mira meses anteriores. |
| Gratificación trunca | `LiquidacionCeseService::previsualizar()` [`LiquidacionCeseService.php:90-105`] | `fecha_ingreso`, `tasa_gratificacion` (`ParametrosVigentesResolver`) | Solo el semestre en curso (ene-jun o jul-dic) | `(sueldo/6) × meses completos del semestre × tasa` | `colaboradores`, `parametro_laboral_valores` | **Bajo**, salvo que el cese ocurra en el PRIMER mes de un semestre cuyo inicio real (por fecha de ingreso previa a agosto) ya estaba dentro de Agento — en ese caso la fórmula sigue siendo correcta porque solo cuenta meses del semestre actual, no requiere datos de semestres anteriores. |
| Bonificación extraordinaria (trunca) | Mismo método [`LiquidacionCeseService.php:106-107`] | Monto de la gratificación trunca ya calculada | Igual que arriba | `gratificación trunca × tasa_bonificacion_extraordinaria` | igual que arriba | Igual que gratificación trunca. |
| CTS trunca | `LiquidacionCeseService::previsualizar()` [`LiquidacionCeseService.php:109-127`] | `fecha_ingreso`, `tasa_cts`, **última gratificación pagada** (`BeneficioSocialDetalle`) | Solo el semestre CTS en curso (mayo-oct o nov-abr) | `((sueldo + gratificación_percibida/6) / 360) × días del semestre × tasa` | `beneficio_social_detalles`, `beneficios_sociales`, `colaboradores` | **ALTO — confirmado.** Ver pregunta 1-2 de la sección 7: si la última gratificación fue pagada fuera de Agento (antes de agosto 2026), no hay fila en `beneficio_social_detalles` y `gratificacionPercibida` cae a `0` [`LiquidacionCeseService.php:121`], **subestimando** el CTS trunca. El propio código ya emite una alerta cuando esto pasa [`LiquidacionCeseService.php:122-124`], pero no bloquea el cálculo. |
| Sexto de gratificación en CTS | Mismo método [`LiquidacionCeseService.php:116-125`] | `BeneficioSocialDetalle.bruta` de la última gratificación **pagada** (`estado='pagado'`) antes del cese | — | `gratificación_percibida / 6` sumado al sueldo como base CTS | `beneficio_social_detalles` (join `beneficios_sociales`) | Depende 100% de que esa gratificación exista en Agento — ver arriba. Usa el importe **bruto** (`bruta`), nunca el neto ni un adelanto (confirmado por el nombre de columna leído, sección 7 pregunta 3). |
| Vacaciones truncas | `LiquidacionCeseService::previsualizar()` [`LiquidacionCeseService.php:129-137`] | `fecha_ingreso` completa, `AsistenciaOperacionService::vacacionesTomadas()` [`app/Modules/Asistencia/Services/AsistenciaOperacionService.php:134-159`], `VacacionMovimiento` | Toda la antigüedad del colaborador (`fecha_ingreso` → `fecha_cese`) | `max(0, redondeo(dias_servicio/360 × vacaciones_dias) − vacaciones_tomadas + movimientos_vacaciones)`, luego `(sueldo/30) × días` | `colaboradores`, `asistencia_permisos` (tipo=vacaciones, estado=aprobado), `vacacion_movimientos` | **ALTO — confirmado.** `asistencia_permisos` solo existe desde que Asistencia opera (agosto 2026); cualquier goce de vacaciones anterior no se resta, **sobreestimando** el saldo pendiente. El propio código alerta esto cuando no hay ningún registro en `vacacion_movimientos` [`LiquidacionCeseService.php:133-135`], pero no bloquea el cálculo. |
| Vacaciones ganadas y no gozadas / indemnización vacacional | No existe un concepto separado — el mismo cálculo de "vacaciones truncas" cubre el saldo pendiente completo (ganadas y no gozadas se tratan igual, sin distinguir "dentro del récord" vs. "fuera del récord"). | — | — | — | — | No se localizó ningún cálculo de **indemnización vacacional** (la penalidad adicional de una remuneración por no haber otorgado el descanso dentro del año siguiente, Art. 23 D.Leg. 713) en ningún archivo del repositorio. **Esto requiere validación laboral explícita** — ver sección 20. |
| AFP / ONP | `PlanillaDependienteCalculator::calcularAporteAfpOnp()` [`app/Modules/Nominas/Domain/PlanillaDependienteCalculator.php:173-238`] — se ejecuta como parte del cálculo del mes de cese (vía `CalcularBoletaColaborador`), no dentro de `LiquidacionCeseService` directamente | sistema previsional del colaborador, comisión AFP vigente, RMA vigente a fecha de pago | Solo el mes de cese | ONP: `base × tasa_onp`. AFP: aporte obligatorio + prima de seguro (topada por RMA) + comisión, cada uno con su propia tasa | `parametro_laboral_valores`, `comisiones_afp` | Bajo — solo usa datos del mes en curso. |
| EsSalud | `PlanillaDependienteCalculator::calcularEsSalud()` [`PlanillaDependienteCalculator.php:240-277`] | igual que arriba | Solo el mes de cese | `MAX(base × tasa_essalud, RMV × tasa_essalud)` (piso legal) | igual que arriba | Bajo. |
| Descuentos / Adelantos / Préstamos | Se enrutan como líneas de egreso genéricas dentro del cálculo del mes de cese (`ColaboradorConceptoPeriodo`, ver `CalcularBoletaColaborador::conceptosDelPeriodo()` [`app/Modules/Nominas/Application/CalcularBoletaColaborador.php:362-390`]) | Conceptos manuales registrados por RR.HH. para el ciclo del mes de cese | Solo el ciclo del mes de cese | Monto ingresado manualmente por RR.HH., sin fórmula propia | `colaborador_conceptos_periodo`, `conceptos_remuneracion` | **No existe ningún concepto de "saldo de préstamo/adelanto pendiente"** que arrastre saldos de meses anteriores — cada adelanto/descuento es una línea manual por ciclo. Si un colaborador tiene un préstamo otorgado antes de agosto con saldo pendiente, **no hay ningún mecanismo en el código que lo recuerde o lo traiga automáticamente a la liquidación** — debe registrarse manualmente como concepto del período de cese. |
| Otros conceptos (bonos/comisiones) | igual que arriba | igual que arriba | igual que arriba | igual que arriba | igual que arriba | Igual que arriba — dependen de que RR.HH. los reingrese manualmente si vienen de antes de agosto. |

## 7. Dependencias históricas

| Cálculo | Solo datos actuales | Necesita meses anteriores | Qué información histórica requiere | Comportamiento si falta |
|---|---:|---:|---|---|
| Remuneración de cese | ✔ | | — | N/A |
| Gratificación trunca / Bonificación extraordinaria | ✔ (salvo excepción abajo) | | Ninguna en el caso normal — solo mira el semestre en curso | N/A |
| CTS trunca (días del semestre) | ✔ | | Ninguna — solo cuenta días del semestre CTS vigente | N/A |
| CTS trunca (sexto de gratificación) | | ✔ | La última gratificación pagada, con su importe bruto, registrada en `beneficio_social_detalles` | Alerta informativa + el sexto se computa como `0`, **subestimando** el CTS (`LiquidacionCeseService.php:121-124`) |
| Vacaciones truncas | | ✔ | Todo el historial de goces de vacaciones desde el ingreso (o al menos el saldo consolidado a una fecha de corte) | Alerta informativa si `vacacion_movimientos` está vacío, pero el cálculo **continúa** usando solo antigüedad − permisos registrados en Agento, **sobreestimando** el saldo (`LiquidacionCeseService.php:130-135`) |
| AFP/ONP/EsSalud del mes de cese | ✔ | | — | N/A |

Respuestas puntuales solicitadas:

1. **¿Cómo obtiene la última gratificación pagada para calcular la CTS?** Consulta `BeneficioSocialDetalle` filtrando por el `BeneficioSocial` padre con `estado='pagado'`, `es_version_vigente=true`, el `tipo` de gratificación correspondiente al semestre anterior al CTS, y `pagado_at <= fecha de cese`; si hay varias coincidencias, toma la de `pagado_at` más reciente [`LiquidacionCeseService.php:116-120`].
2. **¿Qué pasa si la gratificación de julio de 2026 fue pagada fuera de Agento?** No aparece en `beneficio_social_detalles` (esa tabla solo se llena vía `BeneficioSocialService::calcular()`, que a su vez solo suma `boleta_conceptos` de boletas calculadas en Agento — [`BeneficioSocialService.php:198-242`]). El resultado es `gratificacionPercibida = 0`, con la alerta ya mencionada. **Confirmado, no es una inferencia.**
3. **¿Utiliza el importe bruto, neto, adelanto o pago final?** Usa la columna `bruta` de `BeneficioSocialDetalle` [`LiquidacionCeseService.php:121`], es decir el importe bruto de la gratificación calculada por Agento — nunca neto, adelanto ni un monto pagado fuera del sistema.
4. **¿Cómo determina los días de vacaciones pendientes?** `round(dias_servicio_totales / 360 × vacaciones_dias_por_regimen) − dias_gozados_en_agento + ajustes_manuales`, sin piso en 0 hasta el resultado final [`LiquidacionCeseService.php:129-132`].
5. **¿Resta vacaciones gozadas antes de agosto?** No. `vacacionesTomadas()` solo consulta `asistencia_permisos` [`AsistenciaOperacionService.php:134-159`], tabla que no existe antes de que Asistencia empezara a operar en Agento.
6. **¿Existe un saldo inicial o movimiento de apertura?** Sí, a nivel de esquema y de intención de diseño: `vacacion_movimientos.tipo` acepta `devengo_inicial` [`StoreVacacionMovimientoRequest.php:14`], y el controlador lo documenta explícitamente como pensado para "devengo inicial al migrar de otro sistema" [`VacacionMovimientoController.php:13-19`]. **No se pudo confirmar si ya existen filas cargadas** — eso requiere una consulta a la base de datos (sección 19). Para gratificación/CTS **no existe** un mecanismo equivalente.
7. **¿Las provisiones afectan la liquidación o solo son información contable?** Las líneas `CTS_PROVISION`, `GRATIFICACION_LEGAL`, `BONIFICACION_EXTRAORDINARIA` y `VACACIONES_PROVISION` que genera `PlanillaDependienteCalculator::calcularProvisiones()` [`PlanillaDependienteCalculator.php:279-340`] se guardan en `boleta_conceptos` como aportaciones informativas de la planilla MENSUAL — `LiquidacionCeseService` **nunca las lee directamente**; solo usa el resultado ya persistido en `BeneficioSocialDetalle` (que a su vez sí se deriva de sumar esas provisiones, [`BeneficioSocialService.php:218-219`]). Es decir: la provisión mensual no es un pago, pero si nunca se "calcula" el `BeneficioSocial` correspondiente, esas provisiones jamás llegan a `beneficio_social_detalles` ni, por lo tanto, a una futura liquidación.
8. **¿Cómo evita duplicar la planilla de agosto?** `previsualizar()` verifica explícitamente si existe una `Boleta` con `estado='pagada'`, `es_version_vigente=true`, cuyo ciclo se solape con el mes de cese, y **lanza una excepción bloqueante** (no solo una alerta) si el usuario intenta incluir remuneración en ese caso [`LiquidacionCeseService.php:62-68`]. Esta protección es sólida y confirmada.
9. **¿Cómo evita pagar nuevamente una gratificación o CTS?** Solo indirectamente: la fórmula de "trunca" nunca sale del semestre en curso, así que estructuralmente nunca recalcula un semestre ya cerrado. Además, si la gratificación del semestre ACTUAL ya fue pagada (vía `BeneficioSocialDetalle` con `estado='pagado'` para el mismo `tipo`+`año`), `previsualizar()` **bloquea** con excepción si el usuario intenta incluirla de nuevo [`LiquidacionCeseService.php:98-103`]. No hay protección equivalente contra pagar dos veces algo que fue pagado **fuera** de Agento (porque el sistema no puede ver lo que no está registrado).
10. **¿Qué sucede si se calcula dos veces la misma liquidación?** El único camino de escritura es `ColaboradorController::cesar()` → `ColaboradorService::cesar()`, que bloquea la fila del `Colaborador` con `lockForUpdate()` y verifica `!activo || fecha_cese` **antes** de llamar a `guardar()` [`ColaboradorService.php:530-533`]. Un segundo intento sobre el mismo colaborador activo falla con `ValidationException` ("El colaborador ya fue cesado"). Esto está cubierto por un test: `test_cesar_bloquea_un_segundo_cese_sobre_el_mismo_colaborador` (`tests/Feature/LiquidacionCeseServiceTest.php:86`).
11. **¿Qué sucede con un colaborador recontratado?** `ColaboradorService::restaurar()` [`ColaboradorService.php:577-597`] reactiva al MISMO registro (`colaborador_id` sin cambiar), limpia `fecha_cese`/`motivo_cese` y pone `activo=true` — **pero no toca `fecha_ingreso`**. Si luego se vuelve a cesar a esa persona, `LiquidacionCeseService::guardar()` [`LiquidacionCeseService.php:166-168`] apaga la `es_version_vigente` de **cualquier** liquidación anterior de ese `colaborador_id` (comentario del propio código explica que esto es intencional para el caso de "recalcular antes de aprobar" — [`LiquidacionCeseService.php:160-165`]), sin distinguir si esa liquidación anterior pertenece a un vínculo laboral completamente distinto y ya pagado. **Riesgo confirmado, no inferido** — ver sección 8.
12. **¿El sistema diferencia cada vínculo laboral o solamente al colaborador?** Solo al colaborador. `colaboradores` tiene una única fila por persona, identificada de forma única por `(empresa_id, tipo_documento, numero_documento)` [`database/migrations/2026_08_14_000024_create_colaboradores_table.php:59`]. No existe ningún concepto de "vínculo laboral" o "contrato" independiente que agrupe remuneraciones/boletas/liquidaciones por período de empleo — todo cuelga del mismo `colaborador_id` para siempre, incluso a través de un cese y una recontratación.

## 8. Regímenes laborales

Localizados en `RegimenCalculatorFactory::paraRegimen()` [`app/Modules/Nominas/Domain/RegimenCalculatorFactory.php:16-25`] y en el catálogo `Colaborador::REGIMENES_LABORALES` [`app/Modules/Personas/Models/Colaborador.php:42`]:

- **General**, **Micro Empresa**, **Pequeña Empresa** → las tres usan la **misma clase** `PlanillaDependienteCalculator` [`RegimenCalculatorFactory.php:19`]. La diferencia entre ellas no es de código sino de **parámetros configurados** por régimen en `parametro_laboral_valores` (`tasa_cts`, `tasa_gratificacion`, `tasa_bonificacion_extraordinaria`, `vacaciones_dias`) — documentado explícitamente en el docblock de la clase [`PlanillaDependienteCalculator.php:9-21`]. Si `tasa_cts`/`tasa_gratificacion`/`vacaciones_dias` están en `0`, la línea correspondiente simplemente no se genera [`PlanillaDependienteCalculator.php:286, 301, 327`].
- **Locación de Servicios** → `RegimenCalculatorFactory` **no tiene una implementación**; solo existe `CalcularReciboHonorarios` como motor separado para boletas mensuales, pero `LiquidacionCeseService::previsualizar()` **excluye explícitamente** este régimen con una excepción bloqueante: *"La liquidación de beneficios sociales no aplica a locación de servicios"* [`LiquidacionCeseService.php:51-53`]. Un locador no tiene CTS/gratificación/vacaciones en este sistema.
- **Cualquier otro régimen** (Agrario, Construcción Civil, Trabajo del Hogar, etc.) → `RegimenCalculatorFactory` lanza `RuntimeException` explícita: *"todavía no tiene un motor de cálculo implementado"* [`RegimenCalculatorFactory.php:20-23`]. No se puede calcular boleta ni liquidación.

Validación del régimen: `previsualizar()` usa `$colaborador->regimen_laboral ?: 'General'` [`LiquidacionCeseService.php:50`] — si el campo está vacío, **asume "General" por defecto**, nunca falla por régimen vacío. Esto es una decisión de código explícita, no un valor legal — debe validarse si es el comportamiento deseado para colaboradores del Excel cuyo régimen no esté claro.

**Snapshot al liquidar:** sí. `liquidaciones_cese.regimen_laboral_snapshot` se congela en el momento del cálculo [`LiquidacionCeseService.php:173`, columna declarada en `2026_08_31_000104_crear_liquidaciones_cese.php:18`]. Un cambio posterior de `Colaborador.regimen_laboral` **no altera** una liquidación ya guardada, porque `previsualizar()`/`guardar()` solo se ejecutan una vez por liquidación y el snapshot ya quedó persistido — no hay ningún proceso que vuelva a leer `regimen_laboral` en vivo para una liquidación existente.

## 9. Persistencia, estados y auditoría

**`liquidaciones_cese`** [`2026_08_31_000104_crear_liquidaciones_cese.php` + `2026_08_31_000105_endurecer_liquidaciones_cese.php`]:
- Columnas: `empresa_id`, `colaborador_id` (FK), `fecha_cese`, `motivo_cese`, `remuneracion_snapshot`, `regimen_laboral_snapshot`, 4 booleanos `incluir_*`, `total_ingresos`, `total_egresos`, `neto_pagar`, `alertas` (JSON), `estado`, `version`, `es_version_vigente`, `calculado_por`/`calculado_at`, `aprobado_por`/`aprobado_at`, `pagado_por`/`pagado_at`, `referencia_pago`, `anulado_por`/`anulado_at`, `motivo_anulacion`.
- Índices: `[empresa_id, fecha_cese]` y `[colaborador_id, es_version_vigente]` — **ninguno de los dos es `UNIQUE`**. No hay restricción de base de datos que impida dos filas `es_version_vigente=true` para el mismo colaborador; esa garantía es 100% aplicativa (ver preguntas 10-11 arriba).
- Estados: `calculada` → `aprobada` → `pagada`, o `anulada` desde cualquier estado salvo `pagada`/`anulada` [`LiquidacionCeseController.php:68-69`].
- **¿Recalcularse una calculada?** No existe endpoint de "recalcular" — solo `previsualizar()` (sin persistir). La única forma de generar una nueva versión es anular la actual y volver a cesar, o (si nunca se confirmó) simplemente repetir `previsualizar()`.
- **¿Modificarse una aprobada?** No — no hay ningún método que actualice conceptos ni montos de una liquidación ya aprobada.
- **¿Anularse una pagada?** No — `anularYRevertir()` lo bloquea explícitamente [`LiquidacionCeseController.php:68-69`].
- **¿Se conserva la versión anterior?** Sí — `guardar()` nunca hace `UPDATE` sobre una liquidación existente para "corregirla"; apaga `es_version_vigente` y crea una fila nueva con `version+1` [`LiquidacionCeseService.php:166-168`, `176`]. El comentario del propio código aclara que la numeración es sobre **todo** el historial del colaborador, no solo la fila vigente, justamente para no colisionar tras una anulación [`LiquidacionCeseService.php:160-165`].
- **Condiciones de carrera:** protegidas en el punto de escritura real (`ColaboradorService::cesar()`) con `lockForUpdate()` sobre `colaboradores` [`ColaboradorService.php:530`] — dos solicitudes simultáneas de cese del mismo colaborador se serializan: la segunda espera el commit de la primera y luego falla con "ya fue cesado". `anularYRevertir()` usa el mismo patrón (`lockForUpdate` sobre liquidación y colaborador, [`LiquidacionCeseController.php:73-74`]).

**`liquidacion_cese_conceptos`**: `liquidacion_cese_id` (FK, `cascadeOnDelete`), `codigo`, `nombre`, `tipo`, `monto`, `base_utilizada`, `cantidad`, `tasa_aplicada`, `formula_texto`. Sin índice único — no hace falta, porque las filas las genera el propio servicio (nunca entrada de usuario) una vez por liquidación.

**Patrón repetido en todo Nóminas** (Boleta, BeneficioSocial, PlanillaComplementaria, LiquidacionCese): `version` + `es_version_vigente` + snapshot congelado + `calculado_por/at`, `aprobado_por/at`, `pagado_por/at` — es la convención de auditoría ya establecida en el proyecto, útil como referencia de diseño para cualquier tabla nueva (sección 14).

## 10. Tratamiento de agosto y periodos cerrados

Estados de `CicloRemunerativo`: `abierto` → `calculado` → `cerrado` → `pagado`, con `reabierto` como retroceso desde `cerrado` (nunca desde `pagado`) [`CicloRemunerativoService.php:243-278`], y el flag reciente `requiere_recalculo` (`2026_09_04_000115_...php`) que marca un ciclo `calculado`/`reabierto`/`cerrado` cuya asistencia cambió después del cálculo.

Operaciones y su tipo:

| Operación | Lectura / Escritura | ¿Puede tocar agosto si está cerrado/pagado? |
|---|---|---|
| `LiquidacionCeseService::previsualizar()` | Lectura pura (ningún `create`/`update`) | No aplica — nunca escribe |
| `LiquidacionCeseService::guardar()` / `ColaboradorService::cesar()` | Escritura en `liquidaciones_cese`, `liquidacion_cese_conceptos`, `colaboradores`, `colaborador_horario_asignaciones` | **No** — nunca toca `boletas`, `boleta_conceptos` ni `ciclos_remunerativos` |
| `BoletaService::calcularPlanilla()` (recálculo de planilla mensual) | Escritura en `boletas`/`boleta_conceptos` | **No** — bloqueado explícitamente con `ValidationException` si `estado` es `cerrado` o `pagado` [`BoletaService.php:196-199`] |
| `CicloRemunerativoService::registrarConcepto()`/`actualizarConcepto()`/`eliminarConcepto()` | Escritura en `colaborador_conceptos_periodo` | **No** — bloqueado si el ciclo está `cerrado`/`pagado` [`CicloRemunerativoService.php:460-463`] |

**Conclusión confirmada:** calcular una liquidación de cese **no modifica boletas, no modifica el ciclo de agosto, no crea conceptos adicionales en boletas existentes, no reabre periodos, no recalcula beneficios sociales y no duplica importes ya pagados**. Es una operación aditiva sobre tablas propias (`liquidaciones_cese`/`liquidacion_cese_conceptos`) — el único punto de contacto de solo-lectura con agosto es la verificación de solape de boleta pagada [`LiquidacionCeseService.php:62-68`], que **bloquea** en vez de modificar.

## 11. Capacidad histórica existente

| Mecanismo buscado | ¿Existe? | Dónde | Reutilizable para el problema actual |
|---|---|---|---|
| `vacacion_movimientos` con `tipo=devengo_inicial` | **Sí** | `2026_08_31_000105_endurecer_liquidaciones_cese.php` (crea la tabla); `StoreVacacionMovimientoRequest.php:14`; documentado en `VacacionMovimientoController.php:13-19` | **Sí, directamente** — es exactamente el mecanismo pensado para cargar un saldo vacacional al migrar de otro sistema. Limitación real encontrada: `VacacionMovimientoController::store()` **rechaza** el alta si `!$colaborador->activo` [`VacacionMovimientoController.php:33-35`], y `cesar()` inactiva al colaborador en la misma transacción que genera la liquidación — así que la carga histórica de vacaciones **debe ocurrir antes de confirmar el cese**, nunca después. |
| Beneficios importados / "planillas de apertura" | **No** | — | No existe ningún equivalente para gratificación/CTS histórica. `BeneficioSocialDetalle` solo se llena sumando `boleta_conceptos` reales calculados en Agento — no admite una fila "manual" o "importada". |
| `importacion_id` / lote de importación con trazabilidad | **Sí, pero en otro dominio** | `asistencia_importaciones` + `asistencia_importacion_marcaciones` [`2026_08_17_000032_create_asistencia_core_tables.php:27-73`], usado por `ImportarMarcacionesService.php:117` | Es un **patrón de diseño reutilizable** (tabla de lote padre + tabla puente con `unique(importacion_id, registro_id)` para idempotencia), pero no hay código que lo comparta con Nóminas — habría que replicarlo, no extenderlo. |
| Campos `origen`, `fuente`, `es_historico`, `saldo_inicial`, `pagado_externamente`, `referencia_externa`, `fecha_corte` | **No** | Búsqueda `grep -rniE` sobre `app/Modules` y `database/migrations` sin resultados | Ninguno existe hoy en Nóminas ni Personas. |
| Importador de Excel | **Sí, pero para otros dominios** | `app/Modules/Personas/Infrastructure/ColaboradorXlsxReader.php`, `app/Modules/Asistencia/Infrastructure/HorarioXlsxReader.php` y `TransactionXlsxReader.php` | Son la referencia de "cómo Agento ya lee un Excel a un formato validable" (probablemente con `maatwebsite/excel` o similar) — el patrón de lectura es reutilizable, pero ninguno de los tres importa remuneraciones, beneficios ni vacaciones. |

## 12. Huecos confirmados

1. **CTS trunca subestimado** cuando la última gratificación fue pagada fuera de Agento — `gratificacionPercibida=0` en vez del valor real [`LiquidacionCeseService.php:121-125`]. Confirmado por lectura directa del código, no inferido.
2. **Vacaciones truncas sobreestimadas** cuando hubo goce de vacaciones antes de agosto de 2026 — no se resta porque `asistencia_permisos` no tiene esos registros [`LiquidacionCeseService.php:130-135`].
3. **Ningún mecanismo para adelantos/préstamos con saldo arrastrado de antes de agosto** — cada descuento es una línea manual del ciclo de cese, sin memoria de saldos anteriores.
4. **Ninguna fórmula de indemnización vacacional** localizada en el código — si el negocio la requiere, hoy no existe.
5. **Recontratación afecta la visibilidad de liquidaciones anteriores** — al volver a cesar a un colaborador recontratado, la liquidación de su vínculo laboral ANTERIOR (ya pagada, legítima) pierde `es_version_vigente=true` sin haber sido corregida ni anulada realmente [`LiquidacionCeseService.php:166-168`], lo que la oculta de `LiquidacionCeseController::index()` (filtra por `es_version_vigente=true`, [`LiquidacionCeseController.php:18`]).
6. **`vacacion_movimientos` requiere colaborador activo** — no se puede cargar historial vacacional después de confirmado el cese [`VacacionMovimientoController.php:33-35`], lo que impone un orden estricto a cualquier importación.
7. **No hay pruebas automatizadas para `BeneficioSocialService`** (búsqueda sin resultados) — cualquier cambio en la fórmula de la que depende el sexto de gratificación de la CTS no tiene red de seguridad automatizada hoy.
8. **No existe documento/exportación de la liquidación** (ni PDF ni Excel) — solo respuesta JSON de la API.

## 13. Riesgos

| Riesgo | Severidad | Sustento |
|---|---|---|
| Duplicidad de boletas al importar meses históricos como ciclos/boletas normales | **Crítico** (si se optara por la Alternativa A de la sección 14) | `BoletaService::calcularPlanilla()` no tiene ninguna noción de "boleta histórica" — trataría cualquier ciclo importado igual que uno real, arrastrando su lógica de elegibilidad, aprobación y snapshot bancario [`BoletaService.php:191-223`]. |
| Duplicidad de pagos (gratificación/CTS pagada dos veces) | **Alto** | Solo protegido dentro del semestre en curso [`LiquidacionCeseService.php:98-103`]; nada impide que un usuario cargue manualmente una gratificación histórica que coincida con un semestre que Agento sí procesó parcialmente. |
| Reprocesamiento de agosto | **Bajo** | Confirmado en sección 10 que ningún flujo de liquidación toca `boletas`/`ciclos_remunerativos`; el riesgo solo aparecería si la solución elegida decide "rellenar" boletas de agosto retroactivamente (no es necesario para resolver el problema planteado). |
| Alteración de ciclos cerrados | **Bajo** | Bloqueado por `estado` en múltiples puntos ([`BoletaService.php:196-199`], [`CicloRemunerativoService.php:460-463`]). |
| Gratificación usada incorrectamente en la base de CTS | **Alto — ya ocurre hoy** | Ver hueco 1; no requiere ninguna importación para manifestarse, ya está pasando con cualquier cese actual. |
| Vacaciones ya gozadas que vuelvan a pagarse | **Alto — ya ocurre hoy** | Ver hueco 2. |
| Confundir provisión con pago | **Medio** | El código sí distingue (`PlanillaDependienteCalculator::calcularProvisiones()` genera líneas explícitamente marcadas como "provisión referencial" en su `formula_texto`, [`PlanillaDependienteCalculator.php:295, 309, 335`]) — el riesgo es humano/funcional (que alguien interprete un Excel de "provisiones históricas" como si fueran pagos), no de código. |
| Colaboradores sin documento / documento inconsistente entre Excel y Agento | **Medio** | `colaboradores` exige `numero_documento` único por empresa+tipo [`2026_08_14_000024_create_colaboradores_table.php:59`] — un mismatch de documento entre el Excel y Agento simplemente no encontraría coincidencia, lo cual es seguro (falla visible) pero requiere conciliación manual. |
| Recontrataciones | **Alto — confirmado** | Ver hueco 5. |
| Importación duplicada del mismo Excel | **Alto** (si no se diseña con idempotencia) | Hoy no existe ningún mecanismo de idempotencia para nada relacionado a beneficios históricos — el único precedente (`asistencia_importaciones` con `unique(importacion_id, marcacion_id)`) vive en otro módulo y no se reutiliza automáticamente. |
| Diferencias de empresa o régimen entre Excel y Agento | **Medio** | `ParametrosVigentesResolver` resuelve tasas por `empresa_id` + `regimen_laboral` + fecha [`ParametrosVigentesResolver.php:60-165`] — si el Excel no distingue régimen por colaborador, cualquier importación heredaría una tasa incorrecta silenciosamente (no hay validación cruzada). |
| Cambios retroactivos en remuneraciones | **Medio** | `ColaboradorRemuneracion` es un historial append-only por `vigencia_desde` [`ColaboradorRemuneracion.php`] — insertar una fila con `vigencia_desde` histórica es técnicamente posible y no está protegido contra fechas que se solapen con datos ya usados en boletas reales de agosto. |
| Registros "pendientes" del Excel frente a "cancelados" en Agento | **Medio** | No hay ningún campo de estado compartido entre un futuro registro histórico y el estado real (`pagada`/`aprobada`) de Agento — deberá diseñarse explícitamente. |

## 14. Alternativas

**A. Importar meses históricos como ciclos y boletas normales.**
- Ventajas: reutiliza el motor de cálculo tal cual; los históricos "se ven" igual que cualquier mes en las pantallas existentes.
- Desventajas: `BoletaService`/`CicloRemunerativoService` no tienen concepto de "boleta ya paga en otro sistema" — habría que recalcular con reglas actuales (parámetros vigentes HOY, no los que regían en dic-2025) para producir cifras que en realidad ya están cerradas y no deberían cambiar.
- Riesgo para producción: **crítico** — un recálculo real generaría `BoletaConcepto`, alimentaría `BeneficioSocialService::calcularEnVivo()` (que suma automáticamente todo lo que encuentre en `boleta_conceptos`, [`BeneficioSocialService.php:202-209`]) y `IncidenciasPendientesNominaService`, mezclando datos reales con reconstruidos sin forma de diferenciarlos después.
- Impacto en fórmulas actuales: ninguno en el código, pero corrompe los resultados de `BeneficioSocialService` y de cualquier reporte que sume boletas por rango de fechas.
- Reversión: muy difícil — habría que identificar y borrar selectivamente boletas "falsas" en cascada.

**B. Crear tablas independientes de antecedentes históricos** (p. ej. `historial_beneficio_colaborador`, con su propio ciclo de vida).
- Ventajas: cero riesgo de contaminar `boletas`/`beneficios_sociales`; puede modelarse con la granularidad exacta que el negocio necesite (mes a mes, o consolidado).
- Desventajas: `LiquidacionCeseService` tendría que aprender a leer una fuente nueva además de las actuales — dos caminos de lectura para "última gratificación pagada" (uno para datos de Agento, otro para el histórico).
- Riesgo para producción: **bajo** (aditivo puro).
- Impacto en fórmulas actuales: requiere modificar `LiquidacionCeseService::previsualizar()` en los puntos exactos ya identificados (líneas 116-125 para CTS, 130-135 para vacaciones) para que consulten también la tabla nueva.
- Reversión: sencilla — es una tabla nueva sin FKs entrantes desde el resto del sistema.

**C. Registrar beneficios pagados externamente dentro de las tablas actuales, agregando origen y trazabilidad** (p. ej. columnas `origen`, `pagado_externamente`, `referencia_externa`, `importacion_id` en `beneficio_social_detalles` y una tabla equivalente a `vacacion_movimientos` para gratificación/CTS).
- Ventajas: `LiquidacionCeseService` casi no cambia — sigue leyendo `BeneficioSocialDetalle` tal cual, solo que ahora algunas filas tienen `origen='excel_historico'` en vez de `origen='agento'`.
- Desventajas: `BeneficioSocial` (el padre) hoy se calcula SIEMPRE a partir de `boleta_conceptos` reales [`BeneficioSocialService.php:198-242`] — insertar un `BeneficioSocialDetalle` "suelto" sin su `BeneficioSocial` calculado desde cero rompería esa invariante ya documentada explícitamente en el código como regla de oro (docblock de la clase, [`BeneficioSocialService.php:15-27`]).
- Riesgo para producción: **medio** — toca tablas que SÍ tienen lectores existentes (dashboards, `calcularEnVivo()`), así que cualquier fila mal formada podría filtrarse a totales que hoy son 100% derivados de boletas reales.
- Reversión: posible pero requiere limpiar filas por `origen`.

**D. Combinar antecedentes históricos separados (Alternativa B) con movimientos iniciales de vacaciones y beneficios externos** (extender el patrón que YA existe en `vacacion_movimientos` a un "kardex" equivalente para gratificación/CTS, más una tabla de antecedentes consolidados para lo que no encaje en un kardex, como remuneraciones variables históricas).
- Ventajas: reutiliza un patrón que el propio equipo de Agento ya diseñó y probó (`vacacion_movimientos` + su test `test_los_movimientos_de_vacaciones_ajustan_el_saldo_de_la_liquidacion`) en vez de inventar uno nuevo; mantiene `BeneficioSocialService` intacto (nunca lee la tabla nueva); solo `LiquidacionCeseService` aprende una fuente adicional, igual que ya lo hace con `VacacionMovimiento`.
- Desventajas: es la alternativa con más piezas nuevas (una tabla de kardex de beneficios + una tabla de antecedentes consolidados), más trabajo de diseño inicial.
- Riesgo para producción: **bajo** — 100% aditivo, ningún flujo existente cambia de comportamiento cuando no hay antecedentes cargados.
- Reversión: sencilla, igual que B.

## 15. Recomendación técnica

Sin implementar nada: la alternativa **D** es la más consistente con las convenciones que Agento ya usa (versión + snapshot + trazabilidad, mismo espíritu que `vacacion_movimientos`, `PlanillaComplementaria` y el patrón `asistencia_importaciones`) y la única que dejaría el comportamiento actual **exactamente igual** cuando no existan antecedentes cargados — condición que el usuario pidió garantizar explícitamente. La C es una variante más económica de D pero arriesga la invariante documentada de `BeneficioSocialService`; la B sola resuelve el dato pero obliga a decidir caso por caso cómo se concilia con lo ya existente; la A se descarta por riesgo crítico.

Esta recomendación es preliminar y no debe tomarse como decisión final — falta el detalle exacto del Excel (qué columnas, qué nivel de consolidación) para dimensionar el diseño real de las tablas nuevas.

## 16. Archivos potencialmente afectados

| Archivo existente o nuevo | Tipo de cambio | Motivo | Riesgo | Pruebas necesarias |
|---|---|---|---|---|
| `LiquidacionCeseService.php` (líneas 109-137) | Modificación | Leer la nueva fuente histórica para el sexto de gratificación y el saldo de vacaciones | Alto — es la fórmula legal central | Ampliar `tests/Feature/LiquidacionCeseServiceTest.php` con casos "con antecedente" y "sin antecedente" |
| Nueva migración (tabla de antecedentes/kardex histórico) | Creación, exclusivamente aditiva | Persistir lo que el Excel aporta, con trazabilidad | Bajo (no toca tablas existentes) | Test de migración + factory |
| Nuevo modelo(s) Eloquent para la(s) tabla(s) anterior(es) | Creación | — | Bajo | Unit test de casts/relaciones |
| `VacacionMovimientoController.php` / `StoreVacacionMovimientoRequest.php` | Posible ampliación (o un controlador de importación nuevo aparte) | Si se decide cargar el histórico vía este mismo kardex en volumen, en vez de fila por fila | Medio — ya tiene el candado `!activo` a resolver (sección 11) | Test de la regla `!activo` |
| Nuevo importador de Excel (Infrastructure) | Creación | Leer y validar el archivo antes de persistir | Medio | Test con archivos de ejemplo (bien formados y con errores) |
| `BeneficioSocialService.php` | **No debería tocarse** | Su invariante ("siempre suma boleta_conceptos reales") es intencional y ya está documentada como regla de oro | — | — |
| `BoletaService.php` / `CicloRemunerativoService.php` | **No deben tocarse** | Ningún flujo de liquidación necesita alterar boletas ni ciclos | — | — |
| `PlanillaComplementariaService.php` | **No debe tocarse** | Es para ajustar ciclos ya pagados de Agento, no para historial anterior a Agento | — | — |

## 17. Plan seguro de implementación

1. **Diagnóstico** (este documento) — completado.
2. **Diseño** — definir con el propietario el formato exacto del Excel (sección 20) antes de fijar columnas de las tablas nuevas.
3. **Migraciones exclusivamente aditivas** — nuevas tablas, sin tocar ninguna existente.
4. **Importación a tablas temporales / staging** — nunca escribir directo a las tablas de antecedentes desde el parser del Excel.
5. **Validación y conciliación** — cruzar contra `colaboradores` (documento), `boletas` de agosto (evitar solape) y `beneficio_social_detalles` (evitar duplicar lo que Agento ya calculó).
6. **Registro de antecedentes** — mover de staging a las tablas definitivas solo tras aprobación humana explícita.
7. **Integración con vista previa de liquidación** — extender `LiquidacionCeseService::previsualizar()` para leer la nueva fuente y mostrarla desglosada (nunca mezclada silenciosamente con lo calculado en Agento).
8. **Pruebas sobre copia de datos, nunca sobre producción**.
9. **Piloto con un colaborador real**, revisado a mano por RR.HH./contabilidad antes de aprobar.
10. **Despliegue gradual** por lote de colaboradores.
11. **Monitoreo** — alertas ya existentes en `previsualizar()` como plantilla de qué vigilar.
12. **Reversión** — mientras el registro histórico no se haya usado en una liquidación `aprobada`/`pagada`, debe poder eliminarse sin dejar rastro roto (mismo criterio que `PlanillaComplementariaService::eliminar()`, que solo permite borrar en estado `calculada` — [`PlanillaComplementariaService.php:611-620`]).

Cada fase requiere aprobación del propietario antes de avanzar a la siguiente — ninguna se ejecuta en este informe.

## 18. Plan de pruebas

- Unitarias: fórmula de CTS trunca con y sin antecedente de gratificación; fórmula de vacaciones truncas con y sin antecedente de goce.
- Feature: flujo completo de cese de un colaborador con antecedentes cargados vs. uno sin antecedentes (debe comportarse EXACTAMENTE como hoy).
- Regresión: `tests/Feature/LiquidacionCeseServiceTest.php` y `tests/Feature/LiquidacionCeseControllerTest.php` completos deben seguir pasando sin modificación de sus aserciones existentes.
- Conciliación: importar un Excel con un colaborador cuyo documento no exista en Agento → debe fallar visible, nunca crear un colaborador nuevo implícitamente.
- Idempotencia: importar el mismo archivo dos veces → la segunda vez no debe duplicar filas.

## 19. Consultas SQL de diagnóstico (solo lectura — para que el propietario las ejecute)

```sql
-- 1) Estado del/de los ciclo(s) de agosto de la empresa (reemplazar :empresa_id)
-- Valida si agosto está abierto/calculado/cerrado/pagado y si requiere_recalculo.
-- Compartir: id, nombre, estado, requiere_recalculo, fecha_inicio, fecha_fin.
SELECT id, nombre, estado, requiere_recalculo, fecha_inicio, fecha_fin, fecha_pago
FROM ciclos_remunerativos
WHERE empresa_id = :empresa_id
  AND fecha_inicio >= '2026-08-01' AND fecha_fin <= '2026-08-31';

-- 2) Boletas vigentes de los colaboradores a liquidar en agosto (reemplazar :colaborador_ids)
-- Valida si ya existe una boleta pagada que se solaparía con la liquidación.
-- Compartir: colaborador_id, estado, es_version_vigente, total_ingresos, total_egresos, neto_a_pagar.
SELECT b.id, b.colaborador_id, b.ciclo_id, b.estado, b.es_version_vigente, b.total_ingresos, b.total_egresos, b.neto_a_pagar
FROM boletas b
WHERE b.colaborador_id IN (:colaborador_ids)
  AND b.es_version_vigente = 1;

-- 3) Conceptos pagados en agosto por colaborador (sin exponer datos personales, solo códigos y montos)
-- Valida qué códigos de concepto ya se pagaron en agosto para no duplicarlos en la liquidación.
-- Compartir: colaborador_id, codigo, tipo, monto.
SELECT bc.boleta_id, b.colaborador_id, cr.codigo, bc.tipo, bc.monto
FROM boleta_conceptos bc
JOIN boletas b ON b.id = bc.boleta_id
JOIN conceptos_remuneracion cr ON cr.id = bc.concepto_id
WHERE b.colaborador_id IN (:colaborador_ids) AND b.es_version_vigente = 1;

-- 4) Beneficios sociales (gratificación/CTS) existentes para la empresa
-- Valida qué semestres ya se calcularon/pagaron dentro de Agento.
-- Compartir: tipo, anio, version, es_version_vigente, estado, total_colaboradores.
SELECT id, tipo, anio, version, es_version_vigente, estado, total_colaboradores, total_bruto, total_neto
FROM beneficios_sociales
WHERE empresa_id = :empresa_id
ORDER BY anio DESC, tipo;

-- 5) Detalle de la última gratificación pagada por colaborador (para verificar el "sexto" que usará la CTS)
-- Compartir: colaborador_id, tipo, anio, bruta, pagado_at.
SELECT bsd.colaborador_id, bs.tipo, bs.anio, bsd.bruta, bs.estado, bs.pagado_at
FROM beneficio_social_detalles bsd
JOIN beneficios_sociales bs ON bs.id = bsd.beneficio_social_id
WHERE bsd.colaborador_id IN (:colaborador_ids) AND bs.es_version_vigente = 1
ORDER BY bsd.colaborador_id, bs.pagado_at DESC;

-- 6) CTS registrada (misma tabla que gratificación, filtrando tipo)
-- Compartir: colaborador_id, tipo, anio, bruta, estado, pagado_at.
SELECT bsd.colaborador_id, bs.tipo, bs.anio, bsd.bruta, bs.estado, bs.pagado_at
FROM beneficio_social_detalles bsd
JOIN beneficios_sociales bs ON bs.id = bsd.beneficio_social_id
WHERE bsd.colaborador_id IN (:colaborador_ids)
  AND bs.tipo IN ('cts_mayo', 'cts_noviembre')
  AND bs.es_version_vigente = 1;

-- 7) Movimientos vacacionales ya cargados (confirma si vacacion_movimientos tiene datos históricos)
-- Compartir: colaborador_id, fecha, tipo, dias, descripcion.
SELECT colaborador_id, fecha, tipo, dias, descripcion
FROM vacacion_movimientos
WHERE colaborador_id IN (:colaborador_ids)
ORDER BY colaborador_id, fecha;

-- 8) Permisos de vacaciones aprobados dentro de Agento (para ver desde cuándo hay cobertura real)
-- Compartir: colaborador_id, fecha_inicio, fecha_fin, estado.
SELECT colaborador_id, fecha_inicio, fecha_fin, estado
FROM asistencia_permisos
WHERE colaborador_id IN (:colaborador_ids) AND tipo = 'vacaciones'
ORDER BY colaborador_id, fecha_inicio;

-- 9) Liquidaciones de cese existentes para estos colaboradores (detecta si alguno ya tiene una liquidación previa,
--    por ejemplo por una recontratación — ver sección 3, pregunta 11 del informe)
-- Compartir: colaborador_id, fecha_cese, version, es_version_vigente, estado.
SELECT colaborador_id, fecha_cese, version, es_version_vigente, estado, calculado_at, pagado_at
FROM liquidaciones_cese
WHERE colaborador_id IN (:colaborador_ids)
ORDER BY colaborador_id, version;

-- 10) Versión y estado más reciente de cada liquidación (para confirmar cuál es "la vigente" hoy)
-- Compartir: colaborador_id, version, es_version_vigente, estado.
SELECT colaborador_id, MAX(version) AS ultima_version
FROM liquidaciones_cese
WHERE colaborador_id IN (:colaborador_ids)
GROUP BY colaborador_id;
```

Ninguna consulta modifica datos ni incluye nombres/documentos — todas filtran por `colaborador_id` (identificador interno), que el propietario puede resolver internamente antes de compartir el resultado si necesita anonimizar aún más.

## 20. Información que todavía debe proporcionar el propietario

1. El Excel histórico completo (todas las hojas), tal como se solicitó en la primera etapa de este trabajo.
2. Documento de identidad, fecha de cese y motivo de cese de cada colaborador a liquidar.
3. Confirmación de si alguno de estos colaboradores fue cesado y recontratado alguna vez dentro de Agento (dado el hueco confirmado en la sección 12, punto 5).
4. Confirmación de si la gratificación de julio de 2026 (y cualquier CTS de mayo de 2026) se pagó fuera de Agento, con montos e importes brutos.
5. Confirmación de si ya existen registros en `vacacion_movimientos` para estos colaboradores (consulta 7 de la sección 19).
6. Régimen laboral exacto de cada colaborador a liquidar (General/Micro/Pequeña/Locación) — recordando que Locación queda excluida de este flujo por diseño (sección 8).
7. Validación laboral explícita sobre si corresponde calcular **indemnización vacacional** para alguno de estos casos — no se encontró ninguna fórmula existente en el código para eso (sección 6), y su eventual inclusión requiere una decisión de un especialista laboral, no del código.
8. Confirmación de si existen préstamos/adelantos con saldo pendiente de antes de agosto para alguno de estos colaboradores (sección 6, fila de adelantos/préstamos) — hoy no hay ningún mecanismo que los arrastre automáticamente.

## 21. Conclusión

Agento tiene un motor de liquidación de cese funcional, auditado, probado (parcialmente) y con buenas protecciones contra duplicar la planilla de agosto y contra dos liquidaciones simultáneas del mismo colaborador. El problema planteado no exige reescribir ese motor: exige **alimentarlo con dos piezas de información que hoy no tiene** (la última gratificación pagada antes de agosto, y el consumo de vacaciones antes de agosto), siguiendo el mismo patrón de kardex aditivo que el propio equipo ya diseñó para `vacacion_movimientos`. El riesgo más serio no detectado por el planteamiento original del usuario es el de recontratación (sección 12, punto 5) y merece decisión explícita antes de diseñar la importación. No se implementó nada — este documento es exclusivamente diagnóstico.
