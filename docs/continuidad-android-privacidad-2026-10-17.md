# Continuidad — Android y privacidad alojada

Sergio informa que la prueba física desde Android mostró «Conexión correcta. Acceso de lectura confirmado». Queda documentada la lectura autenticada real en producción; no se solicitó ni se copió la contraseña de aplicación y no se repite la prueba. La confirmación no demuestra la privacidad del filesystem alojado.

El contrato local documenta `GET /wp-json/infocontainer/v1/photos/{id}` para metadatos y `GET /wp-json/infocontainer/v1/photos/{id}/file` para el original. Ambos requieren `manage_infocontainer`; las respuestas no exponen `storage_key`, ruta física ni URL pública. La raíz prevista está fuera del document root y las cabeceras de descarga son privadas. Esto está comprobado localmente, no en Hostinger.

## Evidencia pendiente en Hostinger

Sergio debe comunicar únicamente:

1. document root real y raíz privada efectiva de InfoContainer, confirmando que la segunda queda fuera del primero;
2. presencia o ausencia de `photos/`, `trash/` y `ai-temp/` en árboles públicos, uploads, subdominios o CDN;
3. aliases, enlaces simbólicos, junctions o reglas de servidor que apunten a la raíz;
4. permisos resumidos que permitan leer a PHP y ausencia de listado de directorio;
5. cantidad y extensiones de archivos existentes, sin descargar fotografías ni mostrar configuraciones completas.

Un `401` REST, un `404` de un ID inexistente o la ausencia de un archivo inventado no prueban privacidad de una fotografía existente.

## Prueba acotada posterior (no ejecutada)

Con respaldo y autorización, subir una sola imagen sintética de 64×48 píxeles a una inspección `draft`, guardar ID y SHA-256, descargar autenticado, repetir la URL sin autenticación esperando `401`, y eliminar foto e inspección. Confirmar que desaparecen registro, archivo y residuos en `trash/`/`ai-temp/`. No usar fotografías reales ni ejecutar IA; la prueba crea temporalmente un registro y un archivo alojado.

Primera acción de Sergio: comunicar el document root, la raíz privada efectiva y el resultado resumido de aliases/enlaces. La lectura Android queda cerrada documentalmente; la privacidad alojada sigue pendiente.

## Diagnóstico local de almacenamiento — versión 1.3.2

Se confirmó que la versión del plugin pasó de 1.3.1 a 1.3.2. La nueva página administrativa «Diagnóstico de InfoContainer» requiere `manage_options` y muestra versión, `ABSPATH`, document root informado por PHP, raíz privada configurada aunque no exista, resolución de ancestros, estado fuera de directorios públicos comprobables y permisos observables del ancestro. La consulta usa una ruta de diagnóstico sin efectos: no llama a `root()`, no crea carpetas, no crea archivos o registros y no cambia permisos.

La pantalla queda disponible solo para administradores. Un operador con `manage_infocontainer` y un visitante anónimo deben ser rechazados; aliases, subdominios, CDN y reglas del servidor de Hostinger siguen fuera de lo que PHP local puede certificar.

Paquete instalable: `artifacts/infocontainer-1.3.2.zip`, SHA-256 `3CDC0469B0CC7044687ABF9A914866AC02B046EBE6A76BD6AA69FC3F8C443847`. No contiene credenciales, fotografías, configuraciones privadas, Git ni pruebas.

## Prueba administrativa local — versión 1.3.3

Se generó el PNG sintético `artifacts/infocontainer-prueba-sin-datos-reales.png`, marcado «INFOCONTAINER — PRUEBA SIN DATOS REALES», SHA-256 `738058ab3581c712288647f79befee81cc359f52cc1377884423f270131bd8c6`. La pantalla añade un formulario separado con POST, nonce y `manage_options`; rechaza cualquier archivo cuyo hash no coincida antes de inicializar almacenamiento. La ejecución autorizada reutiliza validación y almacenamiento reales, crea la raíz si falta, verifica escritura, lectura, tamaño y hash, y elimina únicamente el archivo creado.

La prueba no crea contenedores, inspecciones, adjuntos de Medios ni filas de fotografías. Debe ejecutarse localmente y luego por Sergio en Hostinger. No se pudo declarar aquí una subida HTTP real completa sin iniciar una sesión administrativa del navegador; la sintaxis PHP y el flujo de control fueron verificados. La prueba no cubre subida Android, descarga REST ni privacidad HTTP completa.
