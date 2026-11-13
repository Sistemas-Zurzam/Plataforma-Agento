<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Correo transaccional con Resend

El backend usa el transporte oficial de Resend de Laravel. Para enviar desde `agento.com.pe`, primero agrega estos registros en la zona DNS del dominio y luego pulsa **Verify DNS records** en Resend (región São Paulo, `sa-east-1`):

| Tipo | Nombre | Contenido | TTL |
| --- | --- | --- | --- |
| TXT | `resend._domainkey` | Copia el valor DKIM completo que muestra Resend; no uses el valor truncado de una captura. | Auto |
| CNAME | `resend` | `resend-sae1.forge.rmeta.net` | Auto |
| CNAME | `send` | `send.forge.rmeta.net` | Auto |
| TXT | `_dmarc` | `v=DMARC1; p=none;` | Auto |

Algunos proveedores DNS agregan automáticamente el dominio al campo **Nombre**. En ese caso ingresa solo la etiqueta mostrada, sin repetir `agento.com.pe`. No habilites recepción de correo en Resend si solo necesitas enviar; la captura muestra esa opción apagada.

Configura el entorno del backend con una API key de Resend y un remitente del dominio verificado:

```dotenv
MAIL_MAILER=resend
RESEND_API_KEY=re_...
MAIL_FROM_ADDRESS=no-reply@agento.com.pe
MAIL_FROM_NAME=Agento
```

Guarda `RESEND_API_KEY` únicamente en el `.env` del servidor o en el gestor de secretos del despliegue; no la subas al repositorio. Después de verificar el dominio en Resend y actualizar el entorno del servidor, limpia la configuración cacheada con `php artisan config:clear` o vuelve a generar el cache con `php artisan config:cache`. Para desarrollo local sin una API key, usa `MAIL_MAILER=log`.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
