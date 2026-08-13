---
name: solid-principles
description: This skill should be used when the user asks to "apply SOLID", "aplicar SOLID", "refactor this class", "refactorizar esta clase", "this component does too much", "este componente hace demasiado", "inject a dependency", "inyectar una dependencia", or when a Controller, Service, Model, React component or hook in the Agento talent-management system codebase appears to mix multiple responsibilities.
version: 0.1.0
---

Aplicar los principios SOLID tanto en `agento-backend` (PHP/Laravel) como en `agento-frontend` (React) para mantener el código mantenible a medida que el sistema crece en módulos (colaboradores, asistencia, nómina, reclutamiento).

## Single Responsibility Principle (SRP)

**Backend**: una clase tiene un único motivo para cambiar.
- Un Controller solo orquesta HTTP → dominio → respuesta (ver skill `laravel-conventions`); no valida, no arma queries complejas, no envía emails.
- Un Service tiene un propósito acotado (`ContratacionService`, no `EmpleadoService` que hace contratación, nómina y reportes a la vez). Si un Service crece y empieza a cubrir varios subdominios, dividirlo.
- Un Model representa una entidad y sus relaciones/atributos; la lógica de negocio compleja no vive en el Model (evitar "Fat Models"), vive en Services/Actions.

**Frontend**: un componente renderiza; un hook maneja datos/estado. Un componente que hace fetch, transforma datos y renderiza una tabla compleja mezcla responsabilidades — separar el fetch/estado en un hook (ver skill `react-antd-conventions`) y dejar el componente enfocado en el render.

## Open/Closed Principle (OCP)

Diseñar para extender sin modificar código existente que ya funciona:
- Preferir agregar un nuevo Service/Strategy para un caso nuevo (p. ej. un nuevo tipo de cálculo de nómina) en vez de agregar un `if`/`switch` más a un método ya existente que crece indefinidamente.
- En React, preferir componer componentes pequeños (props, children) sobre añadir más y más props condicionales a un componente existente para cubrir casos nuevos.

## Liskov Substitution Principle (LSP)

Si se define una interfaz o clase base (p. ej. una interfaz `NotificacionChannel` con implementaciones `EmailChannel`, `SmsChannel`), cualquier implementación debe poder sustituir a la interfaz sin romper el comportamiento esperado por quien la consume — no lanzar excepciones inesperadas ni cambiar el contrato de retorno entre implementaciones.

## Interface Segregation Principle (ISP)

No forzar a una clase a depender de métodos que no usa. Preferir varias interfaces pequeñas y específicas sobre una interfaz grande de propósito general, especialmente al definir contratos entre Services y sus dependencias externas (storage, notificaciones, terceros).

## Dependency Inversion Principle (DIP)

**Backend**: los Services dependen de abstracciones (interfaces o contratos de Laravel), no de implementaciones concretas instanciadas con `new` dentro del método. Inyectar dependencias por el constructor:

```php
class ContratacionService
{
    public function __construct(
        private readonly NotificacionChannel $notificador,
    ) {}
}
```

Esto permite testear el Service sustituyendo `NotificacionChannel` por un mock, sin tocar la clase.

**Frontend**: un componente no debe depender directamente de la implementación de Axios ni de detalles de la API; depende del hook (`useEmployees()`), que actúa como la abstracción. Si la fuente de datos cambia, el componente no se modifica.

## Señales de violación a vigilar

- Una clase o componente con más de una razón de negocio para cambiar (SRP).
- Un método que agrega un `if` nuevo cada vez que aparece un caso de negocio nuevo, en vez de extender vía composición (OCP).
- Un Service que instancia sus propias dependencias con `new` en vez de recibirlas inyectadas (DIP).
- Un componente React que mezcla fetch de datos, lógica de transformación y JSX de presentación en el mismo archivo sin extraer un hook (SRP aplicado a frontend).

Al detectar cualquiera de estas señales durante una tarea, proponer la extracción/reorganización correspondiente en vez de seguir agregando código al mismo lugar.
