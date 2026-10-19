# Etapa 2: fotografías de inspecciones

InfoContainer `1.2.0` añade fotografías a la base de la etapa 1. La versión del esquema es `1.1.0`. La actualización añade `{prefix}infocontainer_photos`; conserva las tablas de contenedores e inspecciones, sus registros y las seis operaciones REST anteriores. La activación y la actualización de un plugin ya activo utilizan la misma instalación versionada.

La tabla de fotografías tiene una clave primaria `id`, un índice por `inspection_id` y un índice único compuesto por `inspection_id, client_photo_id`. Almacena identidad del reintento, categoría, descripción, clave relativa del archivo, nombre recibido saneado, MIME, tamaño, dimensiones, SHA-256, fecha de toma opcional y autoría/fechas de creación y modificación. Las tablas de inspecciones y fotografías deben usar InnoDB para los bloqueos de fila; si no lo cumplen, la instalación devuelve un error de configuración y no marca la actualización como completada.

## Contrato REST

La base de las rutas es `/wp-json/infocontainer/v1`. Todas requieren autenticación nativa de WordPress y `manage_infocontainer`, incluidos los archivos. Los usuarios autorizados comparten acceso a los registros, como en la etapa 1. Las contraseñas de aplicación se utilizan sobre HTTPS; las solicitudes desde una sesión web de WordPress utilizan la cookie y el nonce REST correspondientes.

| Método y ruta | Solicitud | Respuesta correcta |
|---|---|---|
| `POST /inspections/{id}/photos` | `multipart/form-data`, exactamente un archivo `file` y metadatos admitidos | `201` con registro; `200` si reconoce el reintento |
| `GET /inspections/{id}/photos` | `page` opcional, `per_page` opcional | `200`, array de registros y cabeceras de paginación |
| `GET /photos/{id}` | Sin cuerpo | `200`, registro |
| `GET /photos/{id}/file` | Sin cuerpo | `200`, bytes originales |
| `PATCH /photos/{id}` | Objeto JSON con al menos un campo modificable | `200`, registro actualizado |
| `DELETE /photos/{id}` | Sin cuerpo ni archivos | `200`, `{"deleted":true,"id":…}` |

El listado usa `id DESC`, `page=1` y `per_page=20` por defecto, con máximo de 100 por página. Devuelve `X-WP-Total` y `X-WP-TotalPages`. Una página sin elementos devuelve `[]`. Los ID y los parámetros de paginación son enteros positivos.

Subir, editar o eliminar exige una inspección existente cuyo estado siga siendo `draft`. El repositorio inicia una transacción, bloquea la inspección con `SELECT … FOR UPDATE`, verifica el estado y ejecuta la mutación en esa misma conexión. Los futuros cambios de estado deberán utilizar el mismo bloqueo. Las consultas y descargas siguen disponibles para usuarios autorizados cuando una inspección deja de ser borrador.

### Metadatos enviados por el cliente

| Campo | Alta | PATCH | Validación |
|---|---|---|---|
| `client_photo_id` | Obligatorio | Inmutable | UUID con guiones; se normaliza a minúsculas |
| `category` | Obligatorio | Opcional | Uno de los valores de la tabla siguiente |
| `description` | Opcional; por defecto `""` | Opcional | Texto UTF-8 plano, hasta 2.000 caracteres; se sanea como texto de varias líneas |
| `captured_at` | Opcional; omitir si se desconoce | Opcional; `null` elimina la fecha de toma | ISO 8601 con segundos y zona explícita `Z` o `±HH:MM` |

En PATCH, omitir un campo conserva su valor. `description: ""` vacía la descripción. `captured_at: null` elimina una fecha de toma previa. En el alta, una fecha desconocida se expresa omitiendo `captured_at`, no enviando `null` en multipart.

| `category` | Etiqueta en español |
|---|---|
| `identifier` | Identificador del contenedor |
| `technical_plate` | Placa técnica |
| `doors` | Puertas |
| `front` | Frente |
| `left_side` | Lateral izquierdo |
| `right_side` | Lateral derecho |
| `interior` | Interior |
| `floor` | Piso |
| `damage` | Daños |
| `other` | Otra fotografía |

El cliente no puede imponer `id`, `inspection_id`, `storage_key`, `original_filename`, `mime_type`, `byte_size`, `width`, `height`, `sha256`, `captured_at_utc`, autoría ni fechas de registro. También se rechazan los campos desconocidos. El vínculo con la inspección se toma de la URL del alta y permanece inmutable.

Las respuestas muestran los metadatos públicos, incluido el SHA-256, pero nunca `storage_key`, rutas físicas ni URL de la biblioteca de medios. Las fechas se devuelven como UTC con sufijo `Z`; `captured_at_utc` puede ser `null`. `created_at_utc` es la fecha de subida, diferente de la fecha de toma. `updated_by` y `updated_at_utc` registran la última edición y se inicializan también durante el alta.

### Reintentos y eliminación

La identidad del reintento es el par `(inspection_id, client_photo_id)`. Con el mismo par y el mismo contenido se devuelve el registro existente con `200`, sin añadir otro archivo ni cambiar categoría, descripción o fecha de toma. Con contenido diferente se devuelve `409`. Diferentes UUID pueden registrar fotografías con el mismo SHA-256. El bloqueo de la inspección serializa las subidas simultáneas; el índice único es una protección adicional.

Si falla una subida después de crear el archivo privado, se elimina ese archivo nuevo y se revierte la transacción. Los temporales de la subida HTTP se limpian al concluir la solicitud PHP. Si una compensación de archivos falla, se devuelve `500` y el administrador debe revisar el almacenamiento privado.

DELETE mueve primero el archivo a un nombre aleatorio de `trash/`, dentro de la misma raíz y del mismo sistema de archivos. Luego elimina la fila dentro de la transacción. Si falla la operación SQL o el commit, intenta devolver el archivo a su ubicación original. Tras el commit, elimina físicamente el archivo temporal de `trash/`. Solo confirma `200` si esa eliminación concluye. Un fallo de eliminación física posterior al commit devuelve `500`; puede haber desaparecido la fila y quedar un archivo en `trash/` pendiente de revisión. Los errores no incluyen las rutas de esos archivos.

## Almacenamiento privado y validación

Por defecto, la raíz es `dirname(ABSPATH) + /infocontainer-private`. En este sitio LocalWP corresponde a `C:/Users/MUCACRAN/Local Sites/infocontainer/app/infocontainer-private`, hermana del directorio público `app/public`. El despliegue debe comprobar el document root real y que nginx no tenga un alias o enlace público que exponga esa carpeta.

Puede configurarse una ruta absoluta mediante `INFOCONTAINER_PRIVATE_ROOT` o el filtro `infocontainer_private_root`. El filtro tiene la última palabra. Una integración de configuración del sitio puede registrar, antes de utilizar las rutas de fotografías:

```php
add_filter( 'infocontainer_private_root', function ( $default_root ) {
    return 'D:/datos/infocontainer-private';
} );
```

En Linux, el valor equivalente puede ser `/srv/infocontainer-private`. Esta entrega no requiere modificar `wp-config.php`. La raíz debe estar fuera de los directorios públicos, ser legible/escribible por PHP y ser una ubicación de confianza. El código normaliza rutas, resuelve ubicaciones reales, comprueba los destinos de enlaces y trata las diferencias de mayúsculas de Windows. Las claves solo admiten nombres aleatorios generados por el servidor, como `photos/<nombre-aleatorio>.jpg`; se guardan relativas, sin unidad ni ruta absoluta. Las operaciones de lectura, escritura y borrado verifican pertenencia a la raíz privada. En sistemas Unix se restringen los directorios a `0700` y los archivos a `0600`; en Windows debe verificarse también el acceso mediante los permisos del sistema.

No se utiliza la biblioteca de medios ni `.htaccess`. Los bytes originales se conservan: GD solo valida que puedan decodificarse. No se rotan, comprimen ni vuelven a codificar. Las fotografías y sus carpetas privadas deben permanecer fuera de Git.

| Validación | Límite o requisito |
|---|---|
| Archivo | Uno por solicitud, campo `file`; no vacío |
| Formatos | JPEG (`.jpg`, `.jpeg`), PNG (`.png`) y WebP (`.webp`) |
| Identificación | Extensión, MIME real de Fileinfo y comprobación de WordPress deben coincidir |
| Decodificación | GD y decodificador del formato obligatorios; `getimagesize()` solo prepara el control de recursos |
| Tamaño de archivo | Máximo 10 MiB, reducido por límites inferiores efectivos de PHP, WordPress o nginx |
| Dimensiones | Máximo 10.000 píxeles por lado y 20.000.000 píxeles de superficie |
| Presupuesto de decodificación | Máximo 128 MiB, estimación de 8 bytes por píxel más 32 MiB de reserva; debe caber además en la memoria restante de PHP |

Los límites de dimensiones y memoria se aplican conjuntamente: que una imagen tenga menos de 20 millones de píxeles no garantiza que quepa en el presupuesto de decodificación. Se rechazan contenidos corruptos, extensión/MIME incompatible y archivos ejecutables disfrazados; se comprueban también marcadores PHP dentro de imágenes. Fileinfo o GD ausentes producen `503`. La etapa no depende de Imagick.

`upload_max_filesize` limita cada archivo; `post_max_size` limita toda la solicitud, incluido el multipart. `client_max_body_size` de nginx puede impedir que la solicitud llegue a WordPress. Estos límites efectivos deben consultarse en el entorno de destino. Un rechazo de nginx puede ser `413` con HTML. PHP puede avisar y dejar vacíos los campos al exceder `post_max_size`; cuando WordPress recibe la solicitud y su Content-Length, la ruta intenta responder `413` JSON. Errores de autenticación pueden aparecer antes de validar el cuerpo. El cliente debe tolerar respuestas del servidor que no sean JSON en los rechazos previos a WordPress.

Las descargas utilizan `rest_pre_serve_request` exclusivamente para la ruta autorizada `/photos/{id}/file`. Devuelven el MIME real, `Content-Length`, un nombre seguro `infocontainer-photo-<id>.<ext>`, `Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0` y `X-Content-Type-Options: nosniff`. Los errores mantienen el formato JSON REST y no activan el streaming. La descarga binaria no admite `_envelope`: devuelve un error interno `400` que WordPress envuelve en HTTP `200`; hay que leer el campo `status` del envoltorio. [Comportamiento nativo de `_envelope`](https://developer.wordpress.org/rest-api/using-the-rest-api/global-parameters/#_envelope). `_fields` no reduce ni transforma los bytes originales, y HEAD entrega las mismas cabeceras sin cuerpo.

| HTTP | Situación |
|---|---|
| `201` | Nueva fotografía |
| `200` | Consulta, descarga, edición, borrado completado o reintento reconocido |
| `400` | Parámetros/cuerpo inválidos, archivo ausente, parcial o vacío |
| `401` / `403` | Autenticación nativa o permiso insuficiente |
| `404` | Fotografía o inspección inexistente |
| `409` | Inspección fuera de draft o UUID usado con otro contenido |
| `413` | Archivo o recursos de decodificación excedidos |
| `415` | Formato no admitido, incompatible o corrupto |
| `500` | Fallo interno de archivos/base de datos/compensación; sin detalles internos |
| `503` | Configuración privada, motor transaccional o capacidad de validación necesaria no disponibles |

## Ejemplos PowerShell

Ejecutar en la misma sesión que las funciones de preparación del README, antes de su bloque de limpieza. Se reutiliza `Invoke-InfoContainer` con `-FilePath` y `-DownloadPath`: PHP/cURL de Local valida certificado y hostname, no sigue redirecciones y recibe la autorización por stdin. Las credenciales no se guardan en el archivo del helper. Las rutas de imágenes y descargas siguientes son archivos locales del cliente; no son rutas privadas del servidor.

### Alta de las tres imágenes

Sustituir las cuatro rutas de ejemplo por archivos JPEG, PNG y WebP válidos y una carpeta de salida existente. Este bloque crea datos nuevos marcados como `INFOCONTAINER_ETAPA2_TEST` y muestra sus ID.

```powershell
$infoPhotoJpegPath = '<RUTA_JPEG_VALIDO>'
$infoPhotoPngPath = '<RUTA_PNG_VALIDO>'
$infoPhotoWebpPath = '<RUTA_WEBP_VALIDO>'
$infoPhotoDownloadDirectory = '<CARPETA_DESCARGAS_EXISTENTE>'
$infoPhotoContainer = New-InfoContainerTestContainer -IsoCode ''
Write-Host ('Etapa 2 contenedor: id=' + $infoPhotoContainer.id +
    ', identifier=' + $infoPhotoContainer.identifier)
$infoPhotoInspectionReply = Invoke-InfoContainer -Method POST -Route (
    '/containers/' + $infoPhotoContainer.id + '/inspections'
) -Data @{
    inspection_type = 'receipt'
    observations = 'INFOCONTAINER_ETAPA2_TEST fotografías privadas.'
}
Assert-InfoContainerStatus $infoPhotoInspectionReply 201 'Crear inspección de fotografías'
$infoPhotoInspectionId = $infoPhotoInspectionReply.Data.id
Write-Host ('Etapa 2 inspección: id=' + $infoPhotoInspectionId)
$infoPhotoFiles = @{
    jpeg = $infoPhotoJpegPath
    png = $infoPhotoPngPath
    webp = $infoPhotoWebpPath
}
$infoPhotoRows = @{}
foreach ($infoPhotoFormat in @('jpeg', 'png', 'webp')) {
    $infoPhotoClientId = [Guid]::NewGuid().ToString()
    $infoPhotoCreated = Invoke-InfoContainer -Method POST -Route (
        '/inspections/' + $infoPhotoInspectionId + '/photos'
    ) -FilePath $infoPhotoFiles[$infoPhotoFormat] -Data @{
        client_photo_id = $infoPhotoClientId
        category = 'doors'
        description = 'INFOCONTAINER_ETAPA2_TEST ' + $infoPhotoFormat
        captured_at = '2026-10-13T09:00:00-05:00'
    }
    Assert-InfoContainerStatus $infoPhotoCreated 201 ('Subir ' + $infoPhotoFormat)
    Assert-InfoContainerValue (
        $infoPhotoCreated.Data.captured_at_utc -eq '2026-10-13T14:00:00Z'
    ) ('Fecha de toma UTC ' + $infoPhotoFormat)
    Assert-InfoContainerValue (
        -not ($infoPhotoCreated.Data.PSObject.Properties.Name -contains 'storage_key')
    ) ('Clave privada ausente ' + $infoPhotoFormat)
    $infoPhotoRows[$infoPhotoFormat] = $infoPhotoCreated.Data
    Write-Host ('Etapa 2 foto ' + $infoPhotoFormat + ': id=' +
        $infoPhotoCreated.Data.id + ', client_photo_id=' + $infoPhotoClientId)
}
```

### Consulta, descarga, edición y reintentos

```powershell
$infoPhotoList = Invoke-InfoContainer -Route (
    '/inspections/' + $infoPhotoInspectionId + '/photos?page=1&per_page=1'
)
Assert-InfoContainerStatus $infoPhotoList 200 'Listar fotografías paginadas'
Assert-InfoContainerValue ($infoPhotoList.Data.Count -eq 1) 'Una fotografía por página'
Assert-InfoContainerValue ([int] $infoPhotoList.Headers['X-WP-Total'] -eq 3) 'Total de tres fotografías'
Assert-InfoContainerValue (
    $infoPhotoList.Data[0].id -eq $infoPhotoRows['webp'].id
) 'Orden estable ID descendente'
foreach ($infoPhotoFormat in @('jpeg', 'png', 'webp')) {
    $infoPhotoRow = $infoPhotoRows[$infoPhotoFormat]
    $infoPhotoRead = Invoke-InfoContainer -Route ('/photos/' + $infoPhotoRow.id)
    Assert-InfoContainerStatus $infoPhotoRead 200 ('Consultar ' + $infoPhotoFormat)
    $infoPhotoDestination = Join-Path $infoPhotoDownloadDirectory (
        'infocontainer-download-' + $infoPhotoRow.id + '.' + $infoPhotoFormat
    )
    $infoPhotoDownloaded = Invoke-InfoContainer -Route (
        '/photos/' + $infoPhotoRow.id + '/file'
    ) -DownloadPath $infoPhotoDestination
    Assert-InfoContainerStatus $infoPhotoDownloaded 200 ('Descargar ' + $infoPhotoFormat)
    $infoPhotoOriginalHash = (Get-FileHash -LiteralPath $infoPhotoFiles[$infoPhotoFormat] -Algorithm SHA256).Hash
    $infoPhotoDownloadedHash = (Get-FileHash -LiteralPath $infoPhotoDestination -Algorithm SHA256).Hash
    Assert-InfoContainerValue (
        $infoPhotoOriginalHash -eq $infoPhotoDownloadedHash -and
        $infoPhotoDownloadedHash -eq $infoPhotoRow.sha256
    ) ('Bytes originales SHA-256 ' + $infoPhotoFormat)
    Assert-InfoContainerValue (
        $infoPhotoDownloaded.Headers['X-Content-Type-Options'] -eq 'nosniff' -and
        $infoPhotoDownloaded.Headers['Cache-Control'] -match 'private' -and
        [long] $infoPhotoDownloaded.Headers['Content-Length'] -eq $infoPhotoRow.byte_size
    ) ('Cabeceras privadas ' + $infoPhotoFormat)
}
$infoPhotoJpeg = $infoPhotoRows['jpeg']
$infoPhotoPatch = Invoke-InfoContainer -Method PATCH -Route (
    '/photos/' + $infoPhotoJpeg.id
) -Data @{
    category = 'damage'
    description = 'INFOCONTAINER_ETAPA2_TEST observación revisada.'
    captured_at = $null
}
Assert-InfoContainerStatus $infoPhotoPatch 200 'Editar metadatos'
Assert-InfoContainerValue (
    $null -eq $infoPhotoPatch.Data.captured_at_utc -and
    $infoPhotoPatch.Data.category -eq 'damage'
) 'Fecha borrada y categoría editada'
$infoPhotoRetry = Invoke-InfoContainer -Method POST -Route (
    '/inspections/' + $infoPhotoInspectionId + '/photos'
) -FilePath $infoPhotoJpegPath -Data @{
    client_photo_id = $infoPhotoJpeg.client_photo_id
    category = 'other'
    description = 'Este reintento conserva los metadatos editados.'
}
Assert-InfoContainerStatus $infoPhotoRetry 200 'Reintento con el mismo contenido'
Assert-InfoContainerValue (
    $infoPhotoRetry.Data.id -eq $infoPhotoJpeg.id -and
    $infoPhotoRetry.Data.description -eq $infoPhotoPatch.Data.description -and
    $infoPhotoRetry.Data.category -eq 'damage'
) 'Reintento conserva identidad y metadatos'
$infoPhotoConflict = Invoke-InfoContainer -Method POST -Route (
    '/inspections/' + $infoPhotoInspectionId + '/photos'
) -FilePath $infoPhotoPngPath -Data @{
    client_photo_id = $infoPhotoJpeg.client_photo_id
    category = 'doors'
}
Assert-InfoContainerStatus $infoPhotoConflict 409 'UUID con contenido distinto'
$infoPhotoAnonymous = Invoke-InfoContainer -Route (
    '/photos/' + $infoPhotoJpeg.id
) -RequestHeaders @{ Accept = 'application/json' }
Assert-InfoContainerStatus $infoPhotoAnonymous 401 'Metadatos sin autenticación'
$infoPhotoAnonymousFile = Invoke-InfoContainer -Route (
    '/photos/' + $infoPhotoJpeg.id + '/file'
) -RequestHeaders @{ Accept = 'application/json' }
Assert-InfoContainerStatus $infoPhotoAnonymousFile 401 'Archivo sin autenticación'
```

### Eliminar una fotografía nueva

Este bloque utiliza otro UUID y el mismo archivo para comprobar que no se deduplican fotografías solo por su hash. Elimina únicamente esa fotografía nueva; conserva las tres anteriores.

```powershell
$infoPhotoDisposable = Invoke-InfoContainer -Method POST -Route (
    '/inspections/' + $infoPhotoInspectionId + '/photos'
) -FilePath $infoPhotoJpegPath -Data @{
    client_photo_id = [Guid]::NewGuid().ToString()
    category = 'other'
    description = 'INFOCONTAINER_ETAPA2_TEST fotografía para eliminar.'
}
Assert-InfoContainerStatus $infoPhotoDisposable 201 'Mismo hash con otro UUID'
Write-Host ('Foto temporal para borrar: id=' + $infoPhotoDisposable.Data.id)
$infoPhotoDelete = Invoke-InfoContainer -Method DELETE -Route (
    '/photos/' + $infoPhotoDisposable.Data.id
)
Assert-InfoContainerStatus $infoPhotoDelete 200 'Eliminar fotografía temporal'
Assert-InfoContainerValue ($infoPhotoDelete.Data.deleted -eq $true) 'Eliminación confirmada'
$infoPhotoDeletedRead = Invoke-InfoContainer -Route (
    '/photos/' + $infoPhotoDisposable.Data.id
)
Assert-InfoContainerStatus $infoPhotoDeletedRead 404 'Fotografía eliminada inexistente'
$infoPhotoFinalList = Invoke-InfoContainer -Route (
    '/inspections/' + $infoPhotoInspectionId + '/photos'
)
Assert-InfoContainerStatus $infoPhotoFinalList 200 'Lista final'
Assert-InfoContainerValue ($infoPhotoFinalList.Data.Count -eq 3) 'Tres fotografías conservadas'
```

Los resultados reales, la configuración efectiva, las comprobaciones adicionales y los ID conservados se registran en el apartado de verificación de la entrega. Estos ejemplos no sustituyen las pruebas de concurrencia, configuración privada, usuario autenticado sin permiso, imágenes inválidas ni inspecciones fuera de draft.

## Migración a otro servidor

1. Detener temporalmente las mutaciones de fotografías para obtener una copia coherente de base de datos y archivos.
2. Exportar las tablas de InfoContainer y trasladar toda la carpeta privada mediante un canal protegido. Conservar los nombres relativos de los archivos y revisar cualquier `trash/` pendiente antes de dar la migración por concluida.
3. Configurar la nueva raíz absoluta fuera del document root y de cualquier alias público, mediante la constante o filtro. No modificar `storage_key` en la base de datos: es relativa y portable.
4. Dar acceso a la cuenta que ejecuta PHP, comprobar permisos y que ninguna ruta pública permita leer la carpeta. Confirmar Fileinfo y GD con JPEG/PNG/WebP, límites PHP/nginx e InnoDB.
5. Activar o actualizar el plugin, comprobar la versión del esquema y descargar fotografías de las inspecciones migradas con autenticación. Comparar el SHA-256 de las descargas con el guardado en la tabla y con la copia original.
6. Reanudar las mutaciones una vez confirmada la consistencia. No redirigir públicamente las URL hacia los archivos privados ni incluir fotografías en commits o paquetes del plugin.

## Aviso de Imagick

Esta etapa valida las imágenes con Fileinfo, WordPress y GD. No carga ni utiliza Imagick para decodificar ni transformar archivos. Si el PHP configurado por Local emite el aviso de diferencia entre ImageMagick compilado y cargado, debe comprobarse por separado en CLI y web y registrar las extensiones disponibles. Una corrección del paquete de PHP/ImageMagick corresponde al entorno de Local; no se deben ocultar globalmente avisos, sustituir DLL o alterar la configuración SSL para resolverlo desde el plugin. El diagnóstico efectivo y cualquier comprobación pendiente deben quedar en el registro de verificación de esta entrega.
