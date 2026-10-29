# Selección y Reclutamiento

Flujo: solicitud de personal → revisión de RR. HH. → proceso con N plazas → publicación →
postulaciones → evaluación (ATS, pruebas, entrevistas) → selección → contratación en Personas.

## Estructura

- `Domain/`: reglas puras (flujos de estado, `CalculadoraAts`, `CalculadoraPuntajeFinal`,
  `CalificadorPrueba`) y contratos (`ExtractorCv`, `ProveedorCompatibilidad`, `IniciadorOnboarding`).
- `Application/`: casos de uso. `AlcanceSeleccion` resuelve empresa (JWT), permisos y áreas.
- `Infrastructure/`: almacenamiento privado de CV, correo, adaptadores sin proveedor.
- `Http/`: controladores, Form Requests, Resources. Rutas en `routes/seleccion.php`
  (`/api/seleccion` con JWT y `/api/publico` con rate limiting).

## Multiempresa

- La empresa sale del usuario autenticado. Ningún request acepta `empresa_id`.
- Los modelos con `empresa_id` usan `EmpresaScope`, así que el binding de un id de otra empresa
  responde 404. Además, los servicios filtran por empresa de forma explícita.
- En el portal público la empresa se resuelve desde la publicación (`codigo` del proceso).
- El job `EvaluarPostulacionJob` recibe `empresa_id` resuelto por el servidor.

## Roles y permisos (RBAC existente)

| Permiso | Uso |
|---|---|
| `seleccion.solicitudes.crear` | Crear y editar sus solicitudes (GER: solo en sus áreas) |
| `seleccion.solicitudes.decidir` | Aceptar, devolver o rechazar |
| `seleccion.vacantes.gestionar` | Procesos, perfil, publicación, pausa y cierre |
| `seleccion.candidatos.gestionar` | Postulaciones, CV, banco de talentos |
| `seleccion.evaluaciones.gestionar` | Pruebas y entrevistas |
| `seleccion.contratar` | Contratación |
| `seleccion.seguimiento` | Dashboard y progreso |

Áreas de GER = `users.area_id` + áreas donde es `areas.responsable_user_id`. GER no ve CV, notas ni
puntajes. NOM = quien tiene `colaboradores.editar` y recibe las tareas de seguimiento.

## Fórmulas

**ATS `ats-v1`** (`Domain/CalculadoraAts`):
- Un requisito excluyente confirmado como "no cumple" da 0 y el resultado `no_apto`.
- Cobertura de competencias = Σ(peso × min(nivel candidato / nivel requerido, 1)) / Σ(peso de las
  competencias con dato). Una competencia sin dato no cuenta como 0: marca `requiere_revision`.
- Puntaje = 90 × cobertura + 10 × (deseables que cumple / total de deseables). Si no hay deseables,
  puntaje = 100 × cobertura. El tope es 100.
- Si falta información, el resultado es `requiere_revision`. Con información completa es `apto` si
  el puntaje alcanza el umbral (60 por defecto) y `bajo_umbral` si no.
- Cada cálculo se guarda como fila nueva con su desglose, versión de perfil, fórmula y proveedor.
  El puntaje recomienda; descartar siempre lo decide una persona.

**Puntaje final** (`Domain/CalculadoraPuntajeFinal`): media ponderada de ATS, psicometría y
entrevistas con los pesos del perfil vigente (suman 100).
- Un componente "no aplica" se excluye y su peso se reparte en proporción entre los demás.
- Un componente pendiente no se convierte en 0: el puntaje se calcula con los componentes completos
  y se marca como provisional.
- Cada recálculo agrega una fila, así el historial no se sobrescribe.

**Entrevistas**: escala 1–5, normalizada con ((promedio − 1) / 4) × 100. Se conserva la evaluación
de cada evaluador.

**Pruebas**: por dimensión, Σ(valor elegido × peso) / Σ(valor máximo × peso) × 100. El total es la
media de las dimensiones ponderada por su peso. Una pregunta sin responder al cerrar aporta 0.

**Dashboard**: las fórmulas están documentadas en `Application/DashboardService.php`. El costo de
contratación siempre muestra "Sin datos suficientes" porque Agento no registra gastos.

## Proveedor ATS / extracción de CV

Ahora mismo no hay proveedor (`RECLUTAMIENTO_ATS_PROVEEDOR=ninguno`). La postulación usa lo que el
candidato declara y RR. HH. puede registrar evaluaciones manuales. Para integrar un proveedor:

1. Implementar `ExtractorCv` y/o `ProveedorCompatibilidad` en `Infrastructure/`. Usar
   `config('reclutamiento.ats.timeout_segundos')` como timeout y credenciales tomadas de `.env`.
   El modelo no debe tener herramientas ni acciones, y el CV se trata solo como dato.
2. Enlazar la implementación en `ReclutamientoServiceProvider` según `reclutamiento.ats.proveedor`.
3. El proveedor solo aporta evidencia. El puntaje lo sigue calculando `CalculadoraAts`. Si el
   proveedor falla o tarda, el cálculo cae a las reglas y la postulación no se bloquea.

## Onboarding

No existe un módulo de onboarding. `OnboardingPorTareaPendiente` registra una tarea y responde
`pendiente_integracion`. Cuando exista el módulo, se reemplaza el binding de `IniciadorOnboarding`.

## Correo

Se usa el mailer configurado del proyecto. Fuera de `local`/`testing`, con los mailers `log` o
`array` no se envía nada, porque los correos contienen códigos y enlaces que quedarían en el log.
La interfaz muestra el enlace para compartirlo por otro canal.

## Retención del banco de talentos

- Regla de negocio configurable: `RECLUTAMIENTO_RETENCION_MESES`, por defecto 24 meses desde la
  última postulación.
- `php artisan reclutamiento:vencer-candidatos` hace una ejecución de prueba que solo cuenta
  candidaturas. Anonimiza solo con `--ejecutar` **y** `RECLUTAMIENTO_RETENCION_ELIMINAR=true`.
- Qué elimina o anonimiza: datos personales, archivos de CV y documentos, antecedentes, habilidades,
  respuestas de pruebas, desgloses ATS (incluye referencias al proveedor), textos de entrevistas,
  invitaciones e IP del consentimiento.
- Qué conserva: agregados no personales (etapa, estado, fuente, fechas) para los indicadores.
- Nunca toca colaboradores ni expedientes laborales de Personas.
- Excepciones configuradas: candidatos contratados (`RECLUTAMIENTO_RETENCION_EXCLUIR_CONTRATADOS`)
  y candidaturas vigentes.
- Copias de seguridad: la anonimización no alcanza los respaldos de base de datos ni de
  `storage/app/private`. Los respaldos deben rotar dentro del plazo de retención o, al restaurar
  uno, se debe volver a ejecutar el comando antes de usar los datos.

## Programación

`routes/console.php` agenda `reclutamiento:cerrar-intentos-vencidos` cada 5 minutos y
`reclutamiento:vencer-candidatos --ejecutar` cada día a las 03:00. Requiere `php artisan schedule:work`
o el cron de `schedule:run`. Los intentos vencidos también se cierran al consultarlos.
