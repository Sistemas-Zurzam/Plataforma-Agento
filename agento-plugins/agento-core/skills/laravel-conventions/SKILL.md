---
name: laravel-conventions
description: This skill should be used when the user asks to "create a controller", "add an endpoint", "crear un controlador", "agregar un endpoint", "validate a request", "create a Form Request", "create an API Resource", "crear un servicio", "add business logic", or when editing any file under agento-backend/app/Http or agento-backend/app/Services in the Agento talent-management system backend.
version: 0.1.0
---

Aplicar estas convenciones a todo código nuevo o modificado en `agento-backend/` (Laravel 13, PHP 8.3). El objetivo es mantener controllers delgados y la lógica de negocio aislada y testeable.

## Flujo estándar para un endpoint nuevo

Seguir siempre esta cadena de responsabilidad, sin saltarse capas:

1. **Route** (`routes/api.php`) — define el verbo HTTP, la URI y aplica el middleware de autenticación (ver skill `jwt-auth-middleware`).
2. **Form Request** (`app/Http/Requests/`) — valida y autoriza la entrada. Nunca validar con `$request->validate()` inline en el controlador.
3. **Controller** (`app/Http/Controllers/`) — orquesta: recibe el Form Request ya validado, delega la lógica de negocio y devuelve un API Resource. No contiene queries complejas ni reglas de negocio.
4. **Service o Action** (`app/Services/` o `app/Actions/`) — contiene la lógica de negocio no trivial (más de una operación, transacciones, reglas condicionales). Se inyecta en el controlador por constructor.
5. **API Resource** (`app/Http/Resources/`) — da forma a la respuesta JSON. El controlador nunca devuelve un modelo Eloquent ni un array crudo directamente.

## Controllers delgados

Un método de controller debe poder leerse en pocas líneas: recibe el Form Request, llama al Service/Action o al modelo, y retorna el Resource.

Señales de que un controller dejó de ser delgado:
- Contiene un `if`/`switch` con reglas de negocio.
- Arma queries con múltiples `where`, `join` o agregaciones directamente.
- Llama a otros servicios externos (mail, storage, terceros) directamente.

En cualquiera de esos casos, extraer esa lógica a un Service o Action dedicado.

## Form Requests

- Un Form Request por acción relevante (`StoreXRequest`, `UpdateXRequest`), no reutilizar el mismo Request para crear y actualizar si las reglas difieren.
- El método `authorize()` del Form Request es el lugar para autorizaciones simples (p. ej., pertenencia a la empresa activa); no reemplaza el scoping automático de `multitenant-scoping`.
- Las reglas de validación viven únicamente en `rules()`, no repetidas en el controller.

## Services y Actions

Usar un Service (o Action de un solo propósito) cuando:
- La operación involucra más de un modelo o una transacción de base de datos.
- Hay reglas de negocio condicionales que no son simple validación de formato.
- La misma lógica se necesita desde más de un controller o desde un Job/Command.

Los Services reciben sus dependencias por inyección de constructor (ver skill `solid-principles`), no instancian sus dependencias con `new` dentro de los métodos.

## API Resources

- Toda respuesta de un endpoint que devuelve un modelo o colección pasa por un `JsonResource` o `ResourceCollection`.
- El Resource decide qué campos se exponen; nunca exponer atributos sensibles (`password`, tokens) por accidente al no usar Resource.

## Estructura de carpetas esperada

```
app/
├── Http/
│   ├── Controllers/
│   ├── Requests/
│   ├── Resources/
│   └── Middleware/
├── Services/        # lógica de negocio reutilizable
├── Actions/         # operaciones de un solo propósito (alternativa a Services)
└── Models/
```

Antes de crear un Controller, Request, Resource o Service nuevo, revisar si ya existe uno equivalente en el repositorio (regla global de `CLAUDE.md`). No renombrar ni mover archivos existentes sin verificar impacto en rutas y tests.
