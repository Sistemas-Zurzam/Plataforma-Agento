---
name: db-normalization
description: This skill should be used when the user asks to "create a migration", "crear una migracion", "design a table", "disenar una tabla", "add a column", "agregar una columna", "modify the schema", "modificar el esquema", or when creating or editing any file under agento-backend/database/migrations in the Agento talent-management system backend.
version: 0.1.0
---

Todo diseño de esquema (tablas, migraciones) en `agento-backend/database/migrations` debe respetar 1FN, 2FN y 3FN. Aplicar estas reglas de forma práctica al escribir o revisar una migración de Laravel.

## 1FN — Atomicidad

- Cada columna almacena un único valor atómico, no listas ni estructuras compuestas serializadas en un string (`"vacaciones,permiso,licencia"`).
- Si un dato es naturalmente una colección (p. ej. varios teléfonos de un colaborador), modelarlo como tabla relacionada (`telefonos` con `colaborador_id`), no como columna repetida (`telefono_1`, `telefono_2`) ni como JSON que en realidad representa filas.
- Usar tipos de columna específicos (`date`, `decimal`, `enum`/tabla de referencia) en vez de guardar todo como `string` y parsear después.

## 2FN — Dependencia total de la clave primaria

- En tablas con clave primaria simple (`id`), 2FN normalmente ya se cumple si se respeta 1FN.
- En tablas con clave compuesta (pivotes, p. ej. `colaborador_id` + `beneficio_id`), verificar que cada columna adicional depende de **ambas** partes de la clave, no solo de una. Si una columna depende solo de `beneficio_id` (p. ej. `nombre_beneficio`), esa columna pertenece a la tabla `beneficios`, no al pivote.

## 3FN — Sin dependencias transitivas

- Ninguna columna depende de otra columna que no sea la clave primaria. Ejemplo: si una tabla `empleados` tiene `cargo_id` y también `nombre_cargo`, `nombre_cargo` depende de `cargo_id`, no de `id` de empleado — es una dependencia transitiva. Extraer `nombre_cargo` a una tabla `cargos` y referenciarla por `cargo_id`.
- Evitar duplicar en una tabla datos que ya existen en otra solo para ahorrarse un `join`; usar relaciones y `with()` (eager loading) en vez de desnormalizar por conveniencia, salvo que la tarea justifique explícitamente una desnormalización (p. ej. columna calculada para reporting).

## Convenciones de nombres

- Tablas en `snake_case`, plural (`colaboradores`, `empresas`, `cargos`).
- Llaves foráneas como `<tabla_singular>_id` (`empresa_id`, `cargo_id`), usando `foreignId('empresa_id')->constrained()` en vez de definir la columna y la constraint por separado.
- Columnas booleanas con prefijo `is_`/`has_` cuando aclare el significado (`is_active`), evitando nombres ambiguos como `estado` cuando en realidad es un booleano.

## Antes de escribir una migración nueva

- Revisar las migraciones existentes (`0001_01_01_...`) para no romper convenciones ya establecidas por Laravel (`users`, `sessions`, `cache`, `jobs`) ni duplicar tablas que ya cumplen el propósito.
- Toda tabla de negocio nueva necesita la columna `empresa_id` — coordinar con la skill `multitenant-scoping` para el patrón de llave foránea y el Global Scope correspondiente.
- No modificar una migración ya ejecutada/mergeada; crear una migración nueva para alterar el esquema (`Schema::table` en un archivo `_add_x_to_y_table`), salvo que la migración original no se haya compartido/mergeado todavía.
