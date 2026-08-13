---
name: multitenant-scoping
description: This skill should be used when the user asks to "add a model", "agregar un modelo", "create a migration for business data", "crear una migracion", "write a query", "escribir una consulta", "scope by company", "filtrar por empresa", or when editing any model, migration, or query that touches a table with empresa_id in the Agento talent-management system backend.
version: 0.1.0
---

Agento es multiempresa: los datos de negocio pertenecen a una empresa (`empresa_id`) y deben quedar aislados entre empresas. Esta regla es **absoluta** — un fallo de scoping expone datos de una empresa a otra, lo que se considera el peor tipo de bug posible en este sistema.

## Regla central: origen de `empresa_id`

`empresa_id` se obtiene **siempre** del contexto autenticado (usuario resuelto vía el guard JWT, ver skill `jwt-auth-middleware`), **nunca** de un valor enviado por el frontend — ni en el body, ni en query params, ni en headers, ni en la URL.

Consecuencias prácticas:
- Un Form Request nunca valida ni acepta `empresa_id` como campo editable por el cliente.
- Si el frontend envía `empresa_id` en un payload, se ignora al persistir (se sobrescribe con el del contexto autenticado) o se rechaza la request si el valor no coincide con el contexto — nunca se confía en él tal cual.
- Un endpoint que recibe un `id` de recurso (`/employees/{id}`) debe verificar que ese recurso pertenece a la empresa del usuario autenticado antes de devolverlo o modificarlo, no solo buscarlo por `id`.

## Patrón: Eloquent Global Scope

Para evitar tener que repetir `->where('empresa_id', $empresaId)` en cada query, aplicar un Global Scope a los modelos de negocio que tengan `empresa_id`:

```php
// app/Models/Scopes/EmpresaScope.php
class EmpresaScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if ($empresaId = auth('api')->user()?->empresa_id) {
            $builder->where('empresa_id', $empresaId);
        }
    }
}
```

Aplicar el scope en el modelo con `booted()`:

```php
protected static function booted(): void
{
    static::addGlobalScope(new EmpresaScope);
}
```

Con el scope aplicado, cualquier `Model::all()`, `Model::find()` o relación queda automáticamente filtrada por la empresa del usuario autenticado, sin depender de que cada desarrollador recuerde añadir el `where`.

## Migraciones: columna y llave foránea

Toda tabla de negocio nueva (no las tablas base de Laravel como `users`, `sessions`, `cache`) incluye:

```php
$table->foreignId('empresa_id')->constrained()->cascadeOnDelete();
```

posicionada junto a las demás llaves foráneas, y considerar un índice compuesto (`empresa_id` + columna de búsqueda frecuente) si la tabla crecerá mucho, coordinando con la skill `db-normalization`.

## Checklist anti-fuga de datos

Antes de dar por terminado un endpoint o query nuevo sobre datos de negocio, verificar:

- [ ] El modelo tiene el Global Scope de empresa aplicado (o el query añade el filtro explícitamente si el scope no aplica a ese caso).
- [ ] Ningún Form Request acepta `empresa_id` como input del cliente.
- [ ] Las relaciones (`hasMany`, `belongsToMany`) usadas no exponen registros de otra empresa por no heredar el scope (revisar si la relación necesita su propio filtro).
- [ ] Los tests, si existen, cubren el caso de que un usuario de la Empresa A no pueda leer ni escribir datos de la Empresa B.

## Qué no hacer

- No añadir un parámetro `?empresa_id=` a un endpoint como forma de "seleccionar" la empresa a consultar; la empresa activa viene del token, no de la request.
- No desactivar el Global Scope (`withoutGlobalScope`) salvo en contextos explícitamente administrativos/cross-tenant, y solo si la tarea lo pide de forma explícita.
