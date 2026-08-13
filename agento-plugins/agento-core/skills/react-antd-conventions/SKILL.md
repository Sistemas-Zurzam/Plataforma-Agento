---
name: react-antd-conventions
description: This skill should be used when the user asks to "create a component", "crear un componente", "add a hook", "agregar un hook", "call the API from the frontend", "consumir la API", "add a form", "use Ant Design", "usar Ant Design", "style with Tailwind", or when editing any file under agento-frontend/src in the Agento talent-management system frontend.
version: 0.1.0
---

Aplicar estas convenciones a todo código nuevo o modificado en `agento-frontend/` (React 19 + Vite 8 + Ant Design 6 + Tailwind 4 + Axios). El proyecto está hoy en el scaffold por defecto de Vite (`src/App.jsx`, `src/main.jsx`); no existen aún `components/`, `hooks/` ni `services/`. Al crear la primera pieza de cada tipo, establecer la carpeta correspondiente en lugar de acumular todo en `App.jsx`.

## Estructura de carpetas esperada

```
src/
├── components/   # componentes de UI reutilizables, pequeños
├── hooks/        # lógica de datos/API encapsulada (useXxx)
├── services/     # instancia de Axios y funciones de llamada a la API
├── pages/        # (si aplica) componentes de página/ruta
├── App.jsx
└── main.jsx
```

No introducir una estructura distinta (p. ej. arquitectura por features, Redux, etc.) sin que la tarea lo pida explícitamente — evitar inventar arquitectura no solicitada.

## Componentes pequeños y con responsabilidad única

- Un componente hace una cosa: renderiza una porción de UI. Si empieza a manejar `useEffect` con llamadas a la API, estado de carga/error y lógica de transformación de datos además de renderizar, extraer esa lógica a un hook.
- Preferir componentes de presentación (reciben props, renderizan) separados de componentes contenedores (orquestan datos vía hooks).
- Nombrar componentes con PascalCase (`EmployeeTable.jsx`), hooks con camelCase prefijado `use` (`useEmployees.js`).

## Capa de API: Axios en hooks, no en componentes

Toda llamada Axios se encapsula en un hook dedicado dentro de `src/hooks/`, nunca directamente dentro del `useEffect` de un componente de UI. Patrón esperado:

```jsx
// src/hooks/useEmployees.js
import { useState, useEffect } from 'react';
import api from '../services/api';

export function useEmployees() {
  const [data, setData] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    api.get('/employees')
      .then((res) => setData(res.data))
      .catch(setError)
      .finally(() => setLoading(false));
  }, []);

  return { data, loading, error };
}
```

La instancia de Axios (`baseURL`, interceptor para adjuntar el JWT en `Authorization`, manejo centralizado de 401) vive en `src/services/api.js`. No crear una instancia de Axios nueva por componente ni por hook.

## Ant Design + Tailwind

- Ant Design es la librería de componentes base (formularios, tablas, modales, layout). No introducir otra librería de componentes (Material UI, Chakra, etc.) sin justificarlo.
- Tailwind se usa solo para utilidades de espaciado/layout/ajustes puntuales que Ant Design no cubre directamente; no reimplementar componentes que Ant Design ya provee (botones, inputs, tablas) usando solo clases de Tailwind.
- Los formularios de negocio usan el componente `Form` de Ant Design (con sus reglas de validación integradas) en lugar de manejar el estado de cada input manualmente, salvo casos muy simples.

## Antes de crear algo nuevo

Revisar si ya existe un hook o componente equivalente en `src/hooks/` o `src/components/` antes de crear uno nuevo (regla global de `CLAUDE.md`). No modificar `App.jsx`/`main.jsx` más allá de lo necesario para integrar la nueva pieza.
