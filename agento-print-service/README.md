# Agento Print Service

Puente local PowerShell entre el boton **Imprimir en PVC** de Agento y la Epson L8050. Corre en la misma PC que la impresora. No necesita Photo+, SDK ni compilar C#.

## Estado y cambio de geometria (19/09/2026)

El perfil actual es una **correccion candidata pendiente de prueba fisica**. Se verifican por separado la geometria, la vista previa y el evento GDI con PreviewPrintController, que no envia trabajos a la cola. Una simulacion no confirma el alineado fisico.

Los registros anteriores mostraban A4 como area imprimible, incluso al asignar un PaperSize de 54 x 86 mm. Consultando PrintCapabilities con el tipo PVC, este controlador instalado anuncia A4 como unico tamano de documento. La fuente Manual y el tipo PVCIDCardBorderless SI estan expuestos en PrintTicket: las afirmaciones anteriores de que no se podian consultar eran incorrectas.

La nueva implementacion mantiene A4 vertical y situa una sola tarjeta de 54 x 86 mm dentro de la pagina. Compensa el origen del area imprimible y conserva la proporcion de la imagen. No cambia las preferencias globales de Windows.

Las posiciones iniciales se derivaron de una [prueba publicada con una L8050 y su plantilla de bandeja](https://research.reignofcomputer.com/2023/08/29/down-the-rabbit-hole-low-cost-pvc-id-card-printing/). No son coordenadas oficiales de Epson ni una calibracion confirmada para esta unidad.

La referencia PSD tiene lienzo 2101 x 3000 y mascaras (x,y,ancho,alto): izquierda (73,368,566,894), derecha (846,365,568,897). Al ajustar ese lienzo al area imprimible reportada por esta unidad (aprox. 204.05 x 291.04 mm, origen 2.96 mm), y centrar tarjetas de 54 x 86, se obtienen estos origenes aproximados:

| Ranura | X desde borde fisico A4 | Y desde borde fisico A4 |
| --- | --- | --- |
| 1, izquierda | 10.5 mm | 39.0 mm |
| 2, derecha | 85.7 mm | 38.9 mm |

La identificacion izquierda/derecha tambien debe comprobarse en la bandeja real. La configuracion local selecciona **2**, segun el alojamiento indicado por el usuario. No se dibuja en la otra ranura.

Las dos cruces fotografiadas pertenecian a una tarjeta reutilizada en varias pruebas: no demuestran duplicacion por el driver. Los recortes anteriores tampoco demuestran una escala distinta por eje. Se retira la recomendacion de agrandar el dibujo a 62 x 145 mm.

## Configuracion

Copiar `config.example.json` a `config.json`, conservarlo privado y configurar:

- `Token`: secreto local compartido con el navegador.
- `OrigenesPermitidos`: lista de orígenes exactos autorizados. El ejemplo incluye `https://dev.agento.com.pe` y `http://localhost:5173`. No se aceptan comodines. La propiedad antigua `OrigenPermitido` sigue funcionando para instalaciones existentes.
- `NombreImpresora`: nombre de la cola Epson L8050.
- `AnchoMm` / `AltoMm`: 54 / 86.
- `Ranura`: 1 izquierda o 2 derecha.
- `MargenSeguridadMm`: actualmente 0 para imprimir el diseno a tamano completo, sin el marco blanco agregado. Un valor mayor reduce y centra el diseno conservando proporciones y deja un borde blanco. No se aplica al endpoint de calibracion para mantener sus reglas a escala real. La prueba fisica con 1 mm salio alineada, pero el usuario solicito quitar ese margen para igualar su referencia.
- `DesplazamientoXmm` / `DesplazamientoYmm`: ajuste de posicion, inicialmente 0. Positivo mueve a la derecha / abajo.

`PaginaAnchoMm`, `PaginaAltoMm` y `UsarTamanoPredeterminadoDelDriver` son opciones antiguas y ya no se usan. La tarjeta nunca se estira por eje.

En **Preferencias de impresion de Windows**, seleccionar bandeja disco/tarjeta ID y tipo Tarjeta de ID de PVC. El servicio comprueba esas opciones antes de imprimir. Epson [documenta esos ajustes para aplicaciones externas](https://download4.epson.biz/sec_pubs/l8050_series/useg/en/GUID-616CE2FC-0C0E-4626-B3B1-39FB8B2823C3.htm). El tamano A4 se aplica al documento del servicio, no se debe buscar un tamano personalizado de tarjeta.

## Verificar sin gastar tarjetas

Desde esta carpeta:

```powershell
powershell -ExecutionPolicy Bypass -File .\AgentoPrintService.ps1 -VistaPrevia
powershell -ExecutionPolicy Bypass -File .\AgentoPrintService.ps1 -ValidarControlador
powershell -ExecutionPolicy Bypass -File .\Test-PvcLayout.ps1
```

El primer comando guarda `logs/vista-previa-bandeja.png`: pagina A4 completa, con un patron en la ranura seleccionada. El segundo recorre PrintPage con el controlador instalado usando PreviewPrintController, sin enviar una impresion fisica. Ambos terminan sin iniciar el servidor.

## Ejecutar y validar fisicamente

1. Detener el servicio anterior con Ctrl+C en su consola.
2. Iniciar la version nueva:

```powershell
powershell -ExecutionPolicy Bypass -File .\AgentoPrintService.ps1
```

3. Usar una tarjeta PVC imprimible limpia en el alojamiento seleccionado. No superponer pruebas: cada salida debe corresponder a un solo trabajo. No sustituirla por papel suelto dentro de la bandeja.
4. Para una sola prueba del patron, desde OTRA consola en esta carpeta:

```powershell
$cfgPvc = Get-Content .\config.json -Raw | ConvertFrom-Json
Invoke-RestMethod -Method Post -Uri 'http://127.0.0.1:5588/print/calibracion' -Headers @{ 'X-Agento-Token' = $cfgPvc.Token }
```

5. Verificar la cruz unica, los cuatro bordes y que las marcas cada 5 mm conserven esa separacion. Si hay desplazamiento, ajustar X/Y con la medida observada y reiniciar el servicio. Si sale en blanco o en otro alojamiento, revisar primero el mapeo de ranura; no agrandar el dibujo.
6. Con el patron validado, imprimir un carnet desde Agento y verificar su codigo de barras con el lector.

Un resultado HTTP correcto significa que se envio el trabajo a la cola, no que la impresora termino ni que quedo alineado.

## API

- `GET /health`: deteccion del servicio y colas disponibles.
- `POST /print/carnet`: header `X-Agento-Token`, JSON con `imagenBase64`, `colaboradorId`, `cara`.
- Para ocupar ambos alojamientos en un solo paso, `POST /print/carnet` acepta `imagenes`: uno o dos objetos con `imagenBase64`, `colaboradorId`, `cara` y `ranura` (1 izquierda, 2 derecha). Las ranuras no pueden repetirse. El formato individual anterior se mantiene compatible y usa `Ranura` de `config.json`.
- `POST /print/calibracion`: mismo header, sin cuerpo.

El servicio escucha solo en `127.0.0.1:5588`. El token y los logs se excluyen de Git. Los cambios de configuracion requieren reiniciar el servicio.

## Producción: ejecución automática

Agento puede vivir en un servidor, pero este servicio debe ejecutarse en cada PC conectada físicamente a una Epson. La instalación se hace una vez desde PowerShell en esa PC:

```powershell
cd C:\ruta\agento-print-service
powershell -ExecutionPolicy Bypass -File .\Instalar-AgentoPrintService.ps1 -DominioAgento https://dev.agento.com.pe
```

El instalador crea `config.json` si no existe, genera un token aleatorio, registra la tarea **Agento Print Service** para el usuario actual y la inicia oculta. En los siguientes inicios de sesión arranca automáticamente; no hace falta mantener una consola abierta. Si `config.json` ya existe, lo conserva para no cambiar el token ni la calibración.

Para quitar solamente el inicio automático, conservando archivos y configuración:

```powershell
powershell -ExecutionPolicy Bypass -File .\Desinstalar-AgentoPrintService.ps1
```

La web de producción usa HTTPS y el servicio usa el loopback `http://127.0.0.1:5588`. Los navegadores consideran las direcciones loopback potencialmente confiables, aunque versiones recientes pueden solicitar permiso de acceso a la red local. Debe probarse el flujo completo en Chrome/Edge desde `https://dev.agento.com.pe` antes de habilitarlo a usuarios. El servicio responde al preflight CORS y al encabezado de acceso de red privada únicamente para los orígenes configurados.

### Archivos que sí y no se publican

- Se versionan el servicio, `PvcLayout.ps1`, los scripts de instalación, pruebas, README y `config.example.json`.
- `config.json` no se versiona porque contiene el token y la calibración de una PC.
- `logs/` tampoco se versiona porque contiene datos operativos y nombres de colaboradores.
