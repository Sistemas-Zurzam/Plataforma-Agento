---
name: jwt-auth-middleware
description: This skill should be used when the user asks to "protect a route", "add authentication", "proteger una ruta", "agregar autenticacion", "configure JWT", "configurar JWT", "get the authenticated user", "obtener el usuario autenticado", "get the current company/empresa", or when editing JwtMiddleware.php, config/auth.php, config/jwt.php, or routes/api.php in the Agento talent-management system backend.
version: 0.1.0
---

Gobierna cómo se implementa y usa la autenticación JWT (`tymon/jwt-auth`) en `agento-backend`. El paquete está instalado en `composer.json` pero, a la fecha, **no está configurado**: no existe guard `api`, `JwtMiddleware.php` es un stub vacío no registrado, y no existe `routes/api.php`. Verificar siempre el estado real de estos archivos antes de asumir que ya están configurados.

## Reglas invariantes

- Toda ruta de API que requiera un usuario autenticado se protege con el guard JWT, nunca con el guard `web` (basado en sesión).
- El JWT es la única fuente de identidad del usuario y de la empresa activa en cada request (ver skill `multitenant-scoping` para el uso de `empresa_id`).
- No implementar autenticación paralela (cookies de sesión propias, tokens custom) para la API sin justificarlo explícitamente en la tarea.

## Configuración base pendiente (completar solo si la tarea lo requiere)

Si la tarea implica dejar operativa la autenticación JWT, seguir este orden:

1. **Guard `api`** en `config/auth.php`: añadir bajo `guards` una entrada `'api' => ['driver' => 'jwt', 'provider' => 'users']`. No eliminar el guard `web` existente, que sigue sirviendo a las rutas de sesión si las hubiera.
2. **`JWT_SECRET`**: debe generarse con `php artisan jwt:secret` (no escribir un valor manualmente) y quedar en `.env` (no en `.env.example`, que permanece vacío).
3. **`JwtMiddleware.php`**: completar el método `handle()` para intentar autenticar (`auth('api')->parseToken()->authenticate()` o equivalente) y devolver una respuesta 401 JSON estandarizada si el token falta, es inválido o expiró. No lanzar excepciones sin capturar hacia el cliente.
4. **Registro del middleware**: en `bootstrap/app.php`, dentro de `withMiddleware()`, registrar un alias (p. ej. `'jwt' => JwtMiddleware::class`) para poder aplicarlo con `->middleware('jwt')` en las rutas, siguiendo la sintaxis de Laravel 13.
5. **`routes/api.php`**: si no existe, crearlo y registrarlo en `bootstrap/app.php` dentro de `withRouting()` (`api: __DIR__.'/../routes/api.php'`). Todas las rutas de negocio autenticadas viven ahí, no en `routes/web.php`.

## Obtener el usuario y la empresa autenticada

Dentro de un controller, Service o Form Request, obtener el usuario autenticado siempre vía el guard, nunca decodificando el token manualmente:

```php
$user = auth('api')->user();
```

La empresa activa se deriva de ese usuario autenticado (relación `empresa()` o claim del token), nunca de un parámetro enviado por el frontend. Este derivado se documenta en detalle en la skill `multitenant-scoping`.

## Manejo de errores

Las respuestas de error de autenticación deben ser JSON consistentes, por ejemplo:

```php
return response()->json(['message' => 'Token inválido o expirado'], 401);
```

Distinguir en el middleware entre token ausente, inválido y expirado solo si la tarea lo requiere explícitamente; por defecto, un 401 genérico es suficiente para no filtrar detalles internos.

## Qué no hacer

- No decodificar o verificar el JWT manualmente en un controller cuando ya existe el guard `api` configurado.
- No mezclar rutas autenticadas por sesión (`web`) y por JWT (`api`) para el mismo recurso de negocio.
- No modificar `config/jwt.php` (TTL, algoritmo, blacklist) sin que la tarea lo pida explícitamente; los valores por defecto del paquete son el punto de partida.
