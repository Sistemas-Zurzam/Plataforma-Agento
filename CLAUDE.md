# Agento — Sistema de Gestión de Talento Humano

## Descripción del proyecto

Agento es un sistema web de gestión de talento humano y recursos humanos, multiempresa (multi-tenant).

El repositorio está dividido actualmente en dos proyectos principales:

- `agento-backend/`: API REST desarrollada en Laravel.
- `agento-frontend/`: aplicación web desarrollada en React que consume la API.

Los nombres `agento-backend` y `agento-frontend` corresponden a la estructura técnica actual del repositorio. El sistema y dominio funcional se denomina **Agento**.

> Estado actual: proyecto en fase inicial/scaffolding. No asumir que existe lógica de negocio, Controllers, rutas API, Models, Services, esquema multiempresa u otras implementaciones salvo que estén verificadas directamente en el código.

Antes de implementar cualquier funcionalidad, inspeccionar siempre el estado real del repositorio.

---

## Stack real detectado

### Backend

- PHP ^8.3
- Laravel ^13.17
- MySQL como base de datos objetivo del proyecto
- `tymon/jwt-auth ^2.3`
- Laravel Pint
- PHPUnit

El `.env.example` puede utilizar SQLite como configuración inicial de Laravel, pero no debe asumirse que esta sea la base de datos definitiva del sistema.

### Frontend

- React ^19
- Vite ^8
- Ant Design ^6
- `@ant-design/icons`
- Tailwind CSS ^4
- `@tailwindcss/vite`
- Axios ^1.19
- ESLint

---

## Estructura del repositorio

### Backend

`agento-backend/`

Mantiene la estructura estándar de Laravel, incluyendo cuando existan:

- `app/Http/Controllers`
- `app/Http/Middleware`
- `app/Models`
- `app/Http/Requests`
- `app/Http/Resources`
- `routes/`
- `database/migrations`
- `config/`

Cuando exista lógica de negocio significativa, podrá organizarse mediante Services o Actions.

No introducir Repository Pattern, Clean Architecture, Hexagonal Architecture u otras capas adicionales automáticamente.

Antes de crear una nueva capa, verificar si existe una necesidad real y si es consistente con la arquitectura actual.

### Frontend

`agento-frontend/`

Aplicación React basada en Vite y organizada bajo `src/`.

Al añadir nuevas funcionalidades, mantener separación clara entre:

- componentes;
- páginas;
- hooks;
- acceso a API;
- utilidades;

según la estructura que realmente exista en el proyecto.

No introducir una arquitectura frontend paralela sin necesidad.

---

## Comandos

### Backend

Ejecutar desde `agento-backend/`:

```bash
composer install
php artisan serve
php artisan migrate
composer test
php artisan test
vendor/bin/pint