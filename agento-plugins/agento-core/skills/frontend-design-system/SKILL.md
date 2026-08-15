---
name: frontend-design-system
description: This skill should be used when the user asks to "crear una interfaz nueva", "qué colores uso", "aplicar el design system", "usar los tokens de Agento", "paleta de colores", "tipografía de Agento", "spacing", "border radius", "qué tamaño de ícono", or when creating/modifying any React component or page under agento-frontend/src that involves visual styling (colores, tipografía, spacing, radios de borde, sombras, íconos).
version: 0.1.0
---

Design System oficial de Agento — fuente de verdad visual del producto. Aplicar estos tokens en toda creación o modificación de interfaces en `agento-frontend/`. Evitar colores, tamaños, spacing o border-radius arbitrarios: usar siempre los valores de este documento.

## Paleta de colores

### Blue

Escala derivada del azul de marca real (`#014693`, tomado del logo) y ya usada en `agento-frontend/src/index.css`. Los stops 100, 600, 900 y 950 son exactamente los tokens CSS existentes (`agento-blue-light`, `agento-blue-bright`, `agento-blue`, `agento-blue-dark`); el resto de la escala se interpola entre esos mismos anclajes para mantener un solo azul de marca consistente en toda la app.

| Token | HEX | RGB | Equivale a (index.css) |
|---|---|---|---|
| `Blue-50` | `#F6F9FE` | `246, 249, 254` | — |
| `Blue-100` | `#E6EEFB` | `230, 238, 251` | `--color-agento-blue-light` |
| `Blue-200` | `#BED4F5` | `190, 212, 245` | — |
| `Blue-300` | `#96BAF0` | `150, 186, 240` | — |
| `Blue-400` | `#6DA0EB` | `109, 160, 235` | — |
| `Blue-500` | `#4387E6` | `67, 135, 230` | — |
| `Blue-600` | `#1C6FE0` | `28, 111, 224` | `--color-agento-blue-bright` |
| `Blue-700` | `#1162C9` | `17, 98, 201` | — |
| `Blue-800` | `#0854AF` | `8, 84, 175` | — |
| `Blue-900` | `#014693` | `1, 70, 147` | `--color-agento-blue` (primario) |
| `Blue-950` | `#001A3D` | `0, 26, 61` | `--color-agento-blue-dark` |

Los stops 50, 200, 300, 400, 500, 700 y 800 son nuevos (no existen todavía como variable CSS). Si un componente los necesita (hover, disabled, fondos tenues, etc.), agregarlos a `@theme` en `index.css` en el momento en que surja esa necesidad real — no crear los 11 de una vez sin uso.

### Colores semánticos de estado (excepción documentada)

La escala Blue es para marca y acciones primarias — no alcanza para comunicar estado (Activo/Inactivo, Completo/Incompleto, Pendiente, éxito/error). Para eso se usan los colores semánticos convencionales de Tailwind/AntD, fuera de la escala de marca:

| Estado | Color |
|---|---|
| Activo / éxito / completo | `green` (`text-green-600`, `bg-green-50`, `Tag color="green"`) |
| Pendiente / advertencia | `orange` (`text-orange-600`, `bg-orange-50`, `Tag color="orange"`) |
| Inactivo / error / feriado / bloqueado | `red` / `default` de AntD según corresponda |

Usar siempre los nombres estándar de Tailwind (`green`, `orange`, `red`) — no variantes como `emerald`/`amber`/`lime` que no tienen una razón funcional distinta y solo fragmentan la paleta.

### Regla de uso

Los colores definidos por el Design System deben consumirse mediante tokens, no valores sueltos.

Evitar valores hardcodeados dispersos como:

```jsx
<div style={{ color: '#2563EB' }}>
```

Preferir las clases/tokens de Tailwind ya definidas en `@theme` (`bg-agento-blue`, `text-agento-blue-dark`, etc.) o, para un stop nuevo de la escala, agregarlo primero a `index.css` antes de usarlo.

## Tipografía

Familia: **Inter**. Pesos disponibles: 400 (Regular), 500 (Medium), 600 (Semibold), 700 (Bold).

| Categoría | Nombre | Tamaño / Line-height | Peso | Tracking |
|---|---|---|---|---|
| Display | XL | 72 / 80 | Bold | -1 |
| Display | L | 60 / 68 | Bold | -0.5 |
| Display | M | 48 / 56 | Bold | 0 |
| Heading | H1 | 40 / 48 | Bold | — |
| Heading | H2 | 32 / 40 | Semibold | — |
| Heading | H3 | 28 / 36 | Semibold | — |
| Heading | H4 | 24 / 32 | Semibold | — |
| Heading | H5 | 20 / 28 | Semibold | — |
| Title | L | 18 / 28 | Semibold | — |
| Title | M | 16 / 24 | Semibold | — |
| Title | S | 14 / 20 | Semibold | — |
| Body | L | 18 / 28 | Regular | — |
| Body | M | 16 / 24 | Regular | — |
| Body | S | 14 / 20 | Regular | — |
| Label | L | 16 / 24 | Medium | — |
| Label | M | 14 / 20 | Medium | — |
| Label | S | 12 / 16 | Medium | — |
| Caption | Caption | 12 / 16 | Regular | — |
| Caption | Overline | 10 / 16 | Medium | 1 |

## Spacing

`4px` `8px` `12px` `16px` `24px` `32px` `48px` `64px`

## Border Radius

| Valor | Uso |
|---|---|
| `0` | tablas |
| `4px` | inputs |
| `8px` | botones |
| `12px` | cards |
| `16px` | cards grandes |
| `9999px` | pills |

## Border Width

| Valor | Uso |
|---|---|
| `1px` | default |
| `2px` | focus ring |
| `4px` | selection state |

## Opacity

| Valor | Uso |
|---|---|
| `100%` | — |
| `75%` | hover |
| `50%` | disabled |
| `25%` | overlay |
| `0%` | hidden |

## Icon Sizes

`16px` `20px` `24px` `32px`
