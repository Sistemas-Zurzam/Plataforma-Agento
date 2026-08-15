Agento — Sistema de Gestión de Talento Humano

Descripción del proyecto

Agento es un sistema web de gestión de talento humano y recursos humanos, multiempresa (multi-tenant).

El repositorio está dividido actualmente en dos proyectos principales:

agento-backend/: API REST desarrollada en Laravel.

agento-frontend/: aplicación web desarrollada en React que consume la API.

También puede existir:

agento-plugins/: plugins y skills propios utilizados por Claude Code para aplicar reglas técnicas y de dominio del proyecto.

Los nombres agento-backend y agento-frontend corresponden a la estructura técnica actual del repositorio.

El sistema y dominio funcional se denomina Agento.

Estado actual: proyecto en fase inicial/scaffolding. No asumir que existe lógica de negocio, Controllers, rutas API, Models, Services, módulos, esquema multiempresa u otras implementaciones salvo que estén verificadas directamente en el código.

Antes de implementar cualquier funcionalidad, inspeccionar siempre el estado real del repositorio.

Stack tecnológico

Backend

PHP ^8.3

Laravel ^13.17

MySQL

tymon/jwt-auth ^2.3

Laravel Pint

PHPUnit

MySQL es el motor de base de datos oficial y actual de Agento.

No cambiar el proyecto a SQLite, PostgreSQL u otro motor sin una solicitud explícita.

Todas las migraciones, índices, claves foráneas y consultas deben ser compatibles con MySQL.

Frontend

React ^19

Vite ^8

Ant Design ^6

@ant-design/icons

Tailwind CSS ^4

@tailwindcss/vite

Axios ^1.19

ESLint

Arquitectura oficial

Agento utiliza como arquitectura objetivo:

Monolito Modular + principios de Arquitectura Hexagonal aplicados de forma pragmática + principios SOLID.

Estas decisiones son complementarias:

Monolito Modular
        +
Hexagonal pragmática
        +
SOLID
        ↓
Arquitectura de Agento

Esto significa que:

el backend continúa siendo una única aplicación Laravel;

existe un único despliegue principal;

se utiliza una única base de datos MySQL;

el sistema se divide internamente por dominios funcionales;

cada módulo debe tener límites y responsabilidades claros;

las reglas de negocio importantes deben mantenerse separadas de HTTP, Eloquent y servicios externos;

SOLID debe aplicarse cuando aporte desacoplamiento, mantenibilidad y testabilidad;

no se debe implementar Arquitectura Hexagonal de forma ceremonial en operaciones CRUD simples;

no se deben crear abstracciones que no resuelvan una necesidad real.

Agento NO utiliza microservicios actualmente.

No introducir microservicios salvo que en el futuro exista una necesidad técnica y operativa demostrable.

Principios arquitectónicos obligatorios

Claude debe respetar siempre los siguientes principios:

Organizar el sistema por dominios funcionales, no por tablas.

Evitar dependencias circulares entre módulos.

Mantener reglas de negocio fuera de Controllers.

Mantener los Controllers delgados.

Separar lógica de aplicación de detalles de infraestructura cuando exista complejidad real.

No introducir abstracciones innecesarias para operaciones simples.

No convertir cada tabla o entidad en un módulo independiente.

Mantener el aislamiento multiempresa como regla transversal.

Priorizar claridad, mantenibilidad y testabilidad sobre sobreingeniería.

Respetar SOLID sin crear interfaces, repositories, factories o DTOs innecesariamente.

Antes de crear una nueva capa, verificar si existe una necesidad real.

Antes de crear algo nuevo, revisar si existe una implementación reutilizable.

No introducir una segunda arquitectura paralela a la arquitectura oficial.

No realizar refactorizaciones masivas como consecuencia de una tarea pequeña.

Arquitectura Backend

Monolito Modular

agento-backend/ continúa siendo una sola aplicación Laravel.

La lógica funcional debe organizarse progresivamente mediante módulos de dominio.

Los principales dominios previstos son:

Personas

Asistencia

Nóminas

Selección y Reclutamiento

Configuración

Reporting

Podrán aparecer otros dominios conforme evolucionen los requerimientos.

No crear todos los módulos anticipadamente.

Un módulo debe existir cuando exista funcionalidad real que justifique su creación.

Estructura conceptual objetivo:

agento-backend/
└── app/
    ├── Modules/
    │   ├── Personas/
    │   ├── Asistencia/
    │   ├── Nominas/
    │   ├── Reclutamiento/
    │   ├── Configuracion/
    │   └── Reporting/
    │
    └── Shared/

No mover automáticamente código existente únicamente para hacer que el árbol de carpetas coincida visualmente con esta arquitectura.

La evolución debe ser progresiva.

Qué constituye un módulo

Un módulo representa un dominio funcional, no una tabla.

Correcto:

Personas
Asistencia
Nominas
Reclutamiento
Configuracion

Incorrecto:

Usuarios
Roles
Permisos
Empresas
Sedes
Cargos
TiposDocumento

Por ejemplo:

Configuracion
├── Empresas
├── Sedes
├── Usuarios
├── Roles
└── Permisos

puede formar parte de un único módulo Configuracion.

No crear un módulo independiente simplemente porque exista una nueva tabla.

Arquitectura Hexagonal pragmática

Los módulos con lógica de negocio significativa pueden utilizar:

Module/
├── Domain/
├── Application/
├── Infrastructure/
└── Presentation/

No es obligatorio crear todas estas carpetas para todos los módulos.

La estructura debe crecer conforme aparezca complejidad real.

Domain

Domain contiene reglas y conceptos propios del negocio.

Puede contener:

reglas de negocio;

políticas;

Value Objects;

servicios de dominio;

contratos;

excepciones de dominio;

entidades de dominio cuando aporten valor.

Ejemplos futuros:

PoliticaVacaciones
PeriodoLaboral
ReglaTardanza
CalendarioLaboral
PeriodoNomina
EstadoColaborador

Domain debe mantenerse lo más independiente posible de detalles técnicos.

No debe depender directamente de:

Controllers;

Form Requests;

API Resources;

JWT;

Axios;

MySQL;

APIs externas concretas;

componentes React.

Application

Application contiene los casos de uso del sistema.

Su responsabilidad es coordinar:

entrada de datos;

reglas de dominio;

transacciones;

persistencia;

comunicación con infraestructura;

interacción con otros módulos cuando corresponda.

Puede contener:

Actions;

Use Cases;

Application Services;

DTOs cuando aporten valor real.

Ejemplos:

CrearColaborador
ActualizarColaborador
CesarColaborador
CambiarRemuneracion
AsignarHorario
SolicitarVacaciones
ProcesarMarcaciones
GenerarNomina
CrearUsuario
AsignarRolUsuario

La capa Application no debe contener detalles visuales ni lógica específica de React.

Infrastructure

Infrastructure contiene implementaciones técnicas.

Puede contener:

Eloquent;

persistencia;

implementaciones de repositorios cuando sean necesarias;

clientes HTTP;

integraciones externas;

adaptadores;

importadores Excel;

exportadores;

almacenamiento;

correo;

generación de archivos;

integración futura con biométricos.

Ejemplo conceptual:

Domain / Application
       ↓
     Contrato
       ↑
Infrastructure
       ↓
Eloquent / MySQL

No crear un Repository para cada Model automáticamente.

Un Repository o Adapter debe existir únicamente cuando tenga una responsabilidad real, por ejemplo:

desacoplar una fuente de datos;

permitir varias implementaciones;

integrar servicios externos;

proteger límites entre módulos;

mejorar significativamente testabilidad;

encapsular una persistencia realmente compleja.

Para CRUD simples, Eloquent puede utilizarse directamente desde la capa Application cuando sea apropiado.

Presentation

Presentation representa la entrada y salida HTTP.

Puede contener:

Controllers;

Form Requests;

API Resources;

rutas relacionadas con el módulo.

Flujo esperado:

HTTP Request
      ↓
Middleware
      ↓
Form Request
      ↓
Controller
      ↓
Application
      ↓
Domain
      ↓
Infrastructure
      ↓
API Resource
      ↓
HTTP Response

Los Controllers deben mantenerse delgados.

Hexagonal pragmática, no ceremonial

No crear automáticamente estructuras como:

Controller
→ Command
→ DTO
→ Handler
→ Port
→ Interface
→ Repository
→ Adapter
→ Mapper
→ Entity
→ Factory

para realizar un CRUD sencillo.

Un catálogo simple como cargos no necesita obligatoriamente múltiples capas si únicamente realiza operaciones CRUD sin reglas de negocio significativas.

En cambio, funcionalidades como:

cálculo de vacaciones;

cálculo de asistencias;

procesamiento de marcaciones;

calendarios rotativos;

historial remunerativo;

cálculo de nómina;

generación de Telecrédito;

generación de FPN;

reglas laborales;

políticas de beneficios;

sí requieren una separación más fuerte de responsabilidades.

Criterio rápido para decidir

Antes de tratar una funcionalidad como "CRUD simple" o como merecedora de Domain/Application separados, responder:

¿El resultado depende de más de una fuente de datos combinada con reglas condicionales?

¿Existen al menos dos casos de prueba unitaria no triviales (más allá del happy path) que tengan sentido probar sin HTTP ni base de datos?

¿La regla de negocio puede cambiar por motivos ajenos a la persistencia o al framework (ej. cambia la ley laboral, no el motor de base de datos)?

Si ninguna respuesta es afirmativa, tratar la funcionalidad como CRUD simple, sin Domain/Application separados.

Si al menos una respuesta es afirmativa, considerar la separación de capas.

SOLID

Agento aplica los principios SOLID junto con Monolito Modular y Arquitectura Hexagonal pragmática.

SOLID sigue siendo obligatorio.

Single Responsibility Principle

Cada clase, componente o servicio debe tener una responsabilidad clara.

Evitar:

Controllers gigantes;

Services que hagan de todo;

Models con demasiada lógica;

componentes React monolíticos.

Open/Closed Principle

Las reglas que puedan variar deben poder extenderse sin modificar constantemente el código central.

Ejemplo:

ExportadorNomina
       ↑
 ┌─────┴─────┐
 │           │
Telecredito  FPN

Liskov Substitution Principle

Las implementaciones de un mismo contrato deben poder intercambiarse sin alterar el comportamiento esperado.

Interface Segregation Principle

Preferir contratos pequeños y específicos.

No crear interfaces gigantes que mezclen responsabilidades.

Dependency Inversion Principle

Las reglas importantes del dominio no deben depender directamente de implementaciones concretas cuando exista una necesidad real de desacoplamiento.

Ejemplo conceptual:

Application / Domain
        ↓
     Interface
        ↑
Infrastructure

SOLID NO significa crear interfaces, repositories, DTOs y factories para toda operación.

Las abstracciones deben tener una justificación real.

Comunicación entre módulos

Los módulos deben evitar acceder indiscriminadamente a implementaciones internas de otros dominios.

Evitar:

Nominas
   ↓
queries complejas directamente
contra tablas internas de Personas
   ↓
queries contra tablas internas
de Asistencia

Cuando exista lógica relevante entre dominios, preferir contratos explícitos.

Ejemplo:

Nominas
   ↓
Application Service
   ↓
Asistencia

La comunicación puede utilizar:

Application Services;

interfaces;

Events de Laravel;

Domain Events;

Jobs;

cuando exista una necesidad concreta.

No utilizar Events para absolutamente todo.

Una llamada directa a un Application Service es válida cuando sea suficiente.

Dependencias entre módulos

Las dependencias deben tener una dirección clara.

Evitar dependencias circulares:

Personas
   ↓
Nominas
   ↓
Personas

Si dos módulos necesitan funcionalidad común, analizar primero dónde pertenece realmente.

Puede existir:

Shared/

pero debe mantenerse pequeño.

No utilizar Shared/, Helpers/, Utils/ o Common/ como contenedores donde colocar lógica sin dominio definido.

Propiedad de datos por módulo

Agento utiliza una única base de datos MySQL.

Monolito Modular NO significa utilizar una base de datos diferente por módulo.

Cada módulo debe tener propiedad conceptual sobre sus tablas.

Ejemplo conceptual:

Personas
├── colaboradores
├── colaborador_remuneraciones
├── colaborador_documentos
└── colaborador_contratos

Asistencia
├── marcaciones
├── asistencias
└── incidencias_asistencia

Nominas
├── nominas
├── nomina_detalles
└── conceptos_nomina

Configuracion
├── empresas
├── sedes
├── usuarios
├── roles
└── permisos

Esto es una referencia conceptual.

No crear tablas sin analizar previamente los requerimientos reales.

Base de datos

Agento utiliza MySQL como motor oficial y actual de base de datos.

Todo diseño debe respetar:

Primera Forma Normal (1FN)

Segunda Forma Normal (2FN)

Tercera Forma Normal (3FN)

Evitar:

columnas multivaloradas;

dependencias parciales;

dependencias transitivas;

información redundante sin justificación;

duplicación de datos fuente;

historiales sobrescritos;

datos derivados almacenados como fuente principal sin necesidad.

La desnormalización solamente debe utilizarse cuando exista una razón técnica demostrable y documentada.

Historial y auditoría

La información laboral que deba conservar trazabilidad no debe sobrescribirse.

Ejemplo:

Si un colaborador cambia de remuneración, debe conservarse el historial remunerativo correspondiente cuando el dominio así lo requiera.

No reemplazar indiscriminadamente información histórica por un único campo mutable.

Las reglas detalladas de cada dominio deberán mantenerse en Skills específicos.

Autenticación

La API utiliza JWT mediante tymon/jwt-auth.

Reglas:

las rutas privadas deben utilizar JWT;

no utilizar sesiones web como autenticación principal de la API;

los Controllers no deben decodificar JWT manualmente;

autenticación y resolución de usuario deben manejarse mediante guards/middleware;

no crear mecanismos paralelos de autenticación sin solicitud explícita.

No reemplazar JWT automáticamente por:

Sanctum;

Passport;

sesiones;

OAuth;

cookies personalizadas;

sin analizar previamente el impacto y recibir una instrucción explícita.

Multitenancy

Agento es multiempresa.

Esta es una regla crítica y transversal.

Cada empresa debe acceder exclusivamente a su propia información.

Esto aplica a entidades como:

colaboradores;

sedes;

usuarios;

horarios;

calendarios;

asistencias;

vacaciones;

remuneraciones;

nóminas;

documentos;

configuraciones;

reportes;

información empresarial futura.

Regla absoluta

Los datos de una empresa nunca pueden cruzarse con los datos de otra empresa.

Ninguna query, relación, búsqueda, endpoint, exportación, reporte, Resource o vista puede exponer datos de otro tenant.

Tenant Context

empresa_id enviado por el frontend nunca debe considerarse una fuente de autorización.

El flujo correcto es conceptualmente:

JWT
 ↓
Usuario autenticado
 ↓
Validación de acceso
 ↓
Tenant Context
 ↓
empresa_id autorizado
 ↓
Módulo
 ↓
Datos

Nunca:

Frontend
 ↓
empresa_id arbitrario
 ↓
Query

Si en el futuro un usuario pertenece a varias empresas, la empresa seleccionada deberá validarse contra las empresas realmente autorizadas para dicho usuario.

El aislamiento no debe depender únicamente de recordar:

->where('empresa_id', $empresaId)

en cada consulta.

La estrategia estructural detallada deberá definirse mediante el skill multitenant-scoping.

Backend — Convenciones Laravel

Los Controllers deben mantenerse delgados.

Pueden:

recibir requests;

delegar validaciones;

invocar casos de uso;

devolver Resources;

manejar aspectos HTTP básicos.

No deben contener:

reglas de negocio complejas;

queries complejas;

cálculos;

resolución manual de tenant;

decodificación manual de JWT.

Form Requests

Utilizar Form Requests para validaciones de entrada cuando corresponda.

Evitar validaciones extensas directamente dentro de Controllers.

API Resources

Utilizar API Resources para controlar respuestas públicas de la API cuando corresponda.

Evitar devolver Models completos sin necesidad.

Services / Actions / Use Cases

La lógica de negocio significativa debe vivir en Application mediante Actions, Use Cases o Services con responsabilidades claras.

No crear Services vacíos únicamente para agregar una capa.

Transacciones

Utilizar transacciones cuando una operación modifique varias entidades y requiera atomicidad.

Arquitectura Frontend

agento-frontend/ utiliza React + Vite.

El frontend debe organizarse progresivamente por feature o dominio funcional.

Preferir:

src/
├── modules/
│   ├── personas/
│   │   ├── pages/
│   │   ├── components/
│   │   ├── hooks/
│   │   └── services/
│   │
│   ├── asistencia/
│   ├── nominas/
│   ├── reclutamiento/
│   └── configuracion/
│
├── shared/
│   ├── components/
│   ├── hooks/
│   └── utils/
│
└── infrastructure/
    └── api/

Esta estructura es conceptual.

Antes de crear o mover carpetas, inspeccionar la estructura real existente.

No realizar refactorizaciones masivas únicamente para que el proyecto coincida visualmente con esta estructura.

Flujo Frontend

Preferir:

Página / Componente
        ↓
       Hook
        ↓
   API Service
        ↓
Axios configurado
        ↓
   API Laravel

Evitar realizar llamadas Axios dispersas directamente en componentes visuales.

Ant Design + Tailwind

Ant Design es la librería principal de componentes UI.

Tailwind CSS se utiliza principalmente para:

layout;

spacing;

flex;

grid;

tamaños;

utilidades visuales.

No incorporar otra librería de UI sin una necesidad explícita.

No sobrescribir estilos internos de Ant Design indiscriminadamente.

Design System

Agento dispone de un Design System oficial definido en Figma.

El Design System es la fuente de verdad visual del producto.

Debe respetarse en toda creación o modificación de interfaces.

Incluye definiciones relacionadas con:

tipografía;

paleta de colores;

tamaños;

espaciados;

border radius;

sombras;

iconografía;

componentes;

estados visuales;

patrones de interacción.

Antes de crear una interfaz nueva:

revisar si existe un patrón equivalente;

reutilizar componentes existentes;

utilizar tokens del Design System;

evitar colores arbitrarios;

evitar tamaños arbitrarios;

evitar spacing arbitrario;

evitar border radius arbitrario;

evitar crear una identidad visual paralela.

Ant Design y Tailwind deben utilizarse de acuerdo con el Design System oficial.

Las reglas visuales detalladas deberán mantenerse en el skill frontend-design-system.

Prioridad del Design System frente a skills externos

Los skills externos de UI/UX son herramientas complementarias.

NO tienen autoridad para sustituir el Design System de Agento.

Orden de prioridad para cualquier decisión visual:

Design System oficial de Agento en Figma.

Tokens y componentes ya implementados en el frontend.

Skill frontend-design-system.

Skill react-antd-conventions.

Skills externos como ui-ux-pro-max.

ui-ux-pro-max puede utilizarse para:

revisar UX;

accesibilidad;

responsive design;

jerarquía visual;

patrones de interacción;

estados de loading/error/empty;

formularios;

tablas;

detectar anti-patrones.

ui-ux-pro-max NO debe:

generar una nueva identidad visual para Agento;

sustituir la paleta oficial;

sustituir la tipografía oficial;

cambiar la escala de spacing;

cambiar border radius;

introducir estilos visuales incompatibles con Figma;

persistir un Design System alternativo que compita con el oficial;

modificar tokens oficiales sin autorización explícita.

Si una recomendación de ui-ux-pro-max contradice el Design System oficial de Agento, prevalece siempre el Design System de Agento.

Seguridad y secretos

Claude nunca debe:

mostrar secretos del .env;

copiar credenciales a código fuente;

commitear .env;

exponer contraseñas;

exponer API Keys;

exponer tokens;

imprimir JWT en logs;

modificar credenciales sin que la tarea lo requiera.

Utilizar variables de entorno para secretos.

Reglas de trabajo con Claude Code

Antes de modificar

Antes de implementar una tarea:

inspeccionar la implementación existente;

entender el requerimiento;

identificar el dominio funcional;

identificar archivos afectados;

revisar dependencias;

buscar implementaciones reutilizables;

analizar el modelo de datos;

evaluar impacto multiempresa;

evaluar impacto sobre contratos existentes;

modificar únicamente lo necesario.

Alcance

No modificar módulos, archivos o funcionalidades ajenas a la tarea solicitada.

Una tarea específica no autoriza una refactorización general.

Si una modificación adicional es necesaria para completar correctamente la tarea, explicar primero su impacto cuando sea significativo.

Reutilización

Antes de crear:

Controller;

Model;

Service;

Action;

Use Case;

Repository;

Interface;

middleware;

endpoint;

componente;

hook;

utilidad;

tabla;

buscar primero si existe una implementación equivalente o reutilizable.

Contratos existentes

No cambiar arbitrariamente:

tablas;

columnas;

claves foráneas;

endpoints;

payloads;

responses;

enums;

estados;

contratos API;

nombres públicos;

sin verificar previamente todas las dependencias.

Eliminación

No eliminar código, tablas, columnas, componentes, rutas, dependencias o relaciones únicamente porque parezcan no utilizados.

Buscar referencias y verificar impacto previamente.

Política de pruebas y ejecución local

Las pruebas funcionales y la validación final de una funcionalidad implementada son responsabilidad del desarrollador.

Claude debe enfocarse en:

analizar;

diseñar;

implementar;

revisar estáticamente;

detectar posibles problemas mediante inspección de código;

indicar los casos que deben probarse manualmente.

Claude debe detenerse después de la implementación y entregar instrucciones de prueba al desarrollador.

No utilizar navegador automáticamente

Claude NO debe abrir ni controlar navegadores por iniciativa propia.

Está prohibido, salvo autorización explícita del usuario:

abrir Google Chrome;

abrir Microsoft Edge;

abrir Firefox;

utilizar Playwright;

utilizar browser automation;

navegar a localhost;

iniciar sesión en Agento;

completar formularios;

presionar botones;

crear registros mediante interfaz;

editar registros mediante interfaz;

eliminar registros mediante interfaz;

tomar screenshots para validar funcionalidades;

ejecutar pruebas E2E;

manipular la aplicación mediante navegador.

Tener Playwright instalado NO constituye autorización para utilizarlo.

Playwright solamente puede utilizarse cuando el usuario lo solicite explícitamente.

Ejemplo de autorización válida:

Prueba esta funcionalidad con Playwright.

Sin una instrucción equivalente, Claude no debe utilizarlo.

No iniciar servidores automáticamente

Claude NO debe iniciar por iniciativa propia:

php artisan serve
npm run dev
composer dev

ni otros procesos persistentes para probar una funcionalidad.

Si se requiere levantar backend o frontend, Claude debe indicar al desarrollador qué comando ejecutar.

No ejecutar pruebas automáticamente

Claude NO debe ejecutar automáticamente:

php artisan test
composer test
npm run test

salvo que el usuario lo solicite explícitamente.

Claude puede crear o modificar tests si la tarea lo requiere, pero no ejecutarlos por iniciativa propia.

No ejecutar lint, format o build automáticamente

Por defecto Claude tampoco debe ejecutar:

vendor/bin/pint
npm run lint
npm run build

sin autorización explícita.

Claude debe indicar cuáles son los comandos recomendados para validar la implementación.

El desarrollador decidirá cuándo ejecutarlos.

Pruebas manuales

Al finalizar una implementación, Claude debe proporcionar una lista breve y específica de pruebas manuales.

Ejemplo:

Pruebas manuales recomendadas:

1. Iniciar sesión con Empresa A.
2. Abrir Configuración → Usuarios y Roles.
3. Verificar que solo aparezcan usuarios de Empresa A.
4. Crear un nuevo usuario.
5. Asignar un rol.
6. Cambiar el rol.
7. Cambiar a Empresa B.
8. Confirmar que ningún usuario de Empresa A sea visible.

Claude debe esperar los resultados enviados posteriormente por el desarrollador.

Regla de autorización de herramientas

La disponibilidad técnica de una herramienta NO significa autorización para utilizarla.

Regla general:

Leer código                  → permitido
Analizar código              → permitido

Modificar código             → permitido cuando forma parte de la tarea
Crear archivos               → permitido cuando forma parte de la tarea

Abrir navegador              → requiere autorización explícita
Usar Playwright              → requiere autorización explícita
Manipular localhost          → requiere autorización explícita
Iniciar servidores           → requiere autorización explícita
Ejecutar tests               → requiere autorización explícita
Ejecutar lint                → requiere autorización explícita
Ejecutar build               → requiere autorización explícita
Ejecutar Pint                → requiere autorización explícita

Claude debe respetar el alcance solicitado aunque tenga acceso técnico al equipo local.

Alcance de una autorización

Una autorización para usar Playwright, iniciar servidores, ejecutar tests, lint, build o Pint aplica únicamente a la tarea o mensaje donde fue otorgada.

No debe interpretarse como autorización permanente para el resto de la conversación ni para tareas futuras.

Si no queda claro si una autorización previa sigue vigente para la tarea actual, Claude debe preguntar antes de ejecutar la herramienta.

Entrega después de una implementación

Después de terminar una tarea, Claude debe informar de forma breve:

Implementación completada.

Archivos modificados:
- ...

Archivos creados:
- ...

Cambios realizados:
- ...

Pruebas manuales recomendadas:
1. ...
2. ...
3. ...

Validaciones opcionales:

Backend:
php artisan test
vendor/bin/pint

Frontend:
npm run lint
npm run build

No ejecutar esas validaciones automáticamente.

Esperar que el desarrollador realice las pruebas y reporte los resultados.

Criterios para crear abstracciones

Antes de crear:

Interface;

Repository;

Adapter;

Factory;

Domain Service;

Application Service;

DTO;

Event;

Job;

evaluar:

¿Existe una regla de negocio real?

¿Existe una dependencia que necesite desacoplarse?

¿Existe o podría existir más de una implementación?

¿Mejora significativamente la testabilidad?

¿Protege un límite entre módulos?

¿Reduce acoplamiento?

¿Simplifica el mantenimiento?

Si ninguna respuesta justifica la abstracción, mantener la solución simple.

La arquitectura debe resolver la complejidad del negocio, no crear complejidad accidental.

Comandos disponibles

Los siguientes comandos existen como referencia para el desarrollador.

Su presencia en este archivo NO autoriza a Claude a ejecutarlos automáticamente.

Backend

Desde agento-backend/:

composer install
php artisan serve
php artisan migrate
composer test
php artisan test
vendor/bin/pint

Antes de utilizar otro comando, comprobar que realmente exista en composer.json o en la configuración actual.

Frontend

Desde agento-frontend/:

npm install
npm run dev
npm run build
npm run lint

Antes de utilizar otro comando, comprobar que esté definido en package.json.

Skills del proyecto

CLAUDE.md contiene las reglas globales de Agento.

Los detalles técnicos especializados deben mantenerse en Skills independientes.

Skills técnicos previstos:

laravel-conventions
jwt-auth-middleware
react-antd-conventions
frontend-design-system
multitenant-scoping
db-normalization
solid-principles

Posteriormente pueden existir Skills o plugins de dominio para:

Personas
Asistencia
Nominas
Reclutamiento
Configuracion
Reporting

Claude debe consultar los Skills correspondientes cuando una tarea pertenezca a esos dominios.

Regla final

Este checklist aplica cuando la tarea introduce un archivo, módulo, capa o abstracción nueva.

No es necesario recorrerlo completo para cambios triviales (ej. un fix de una línea, un texto, un estilo puntual).

Antes de hacer algo que introduce algo nuevo, Claude debe preguntarse:

¿Existe ya?
¿Pertenece al alcance de la tarea?
¿Respeta el módulo?
¿Respeta Hexagonal pragmática?
¿Respeta SOLID?
¿Respeta multitenancy?
¿Respeta MySQL?
¿Respeta el Design System?
¿Estoy introduciendo complejidad innecesaria?
¿Necesito realmente modificar este archivo?
¿Tengo autorización para ejecutar esta herramienta o prueba?

Si alguna respuesta genera duda, inspeccionar primero el código o informar al desarrollador antes de ampliar el alcance.