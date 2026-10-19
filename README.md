# InfoContainer — etapas 1, 2 y 3

Plugin WordPress para registrar contenedores, inspecciones manuales, fotografías privadas y propuestas de IA sujetas a revisión humana mediante una API REST protegida. PDF, interfaz administrativa y aplicación Android corresponden a etapas posteriores.

- Plugin: `1.3.7`.
- Esquema de datos: `1.2.0`, guardado en la opción `infocontainer_db_version`.
- Requisitos mínimos: WordPress `6.0` y PHP `7.4`.
- Sin dependencias externas.
- Repositorio: <https://github.com/mucacran/infocontainer>.

La configuración OpenAI, contratos, confirmación humana, cuotas, vigencia y pruebas de esta etapa están en [docs/etapa-3.md](docs/etapa-3.md).

El cliente OpenAI usa un envío cURL directo con TLS y redirecciones desactivadas. Cada ejecución lógica realiza como máximo una llamada `curl_exec()`; si cURL no está disponible, falla antes de enviar. No se usa el transporte Requests de WordPress para este proveedor.

## Archivos y datos

| Archivo | Responsabilidad |
| --- | --- |
| `infocontainer.php` | Cabecera, constantes, carga de módulos y puntos de entrada del plugin. |
| `includes/class-infocontainer-install.php` | Instalación, comprobación del esquema y capacidad inicial. |
| `includes/class-infocontainer-repository.php` | Acceso seguro a tablas y persistencia de contenedores e inspecciones. |
| `includes/class-infocontainer-validator.php` | Normalización y validación de datos. |
| `includes/class-infocontainer-rest-api.php` | Rutas, permisos y respuestas REST. |

Las tablas usan el prefijo real del sitio, obtenido de `$wpdb->prefix`, y el juego de caracteres/cotejamiento de WordPress. Por ejemplo, con prefijo `wp_`:

| Tabla | Campos |
| --- | --- |
| `wp_infocontainer_containers` | `id`, `identifier`, `iso_code`, `created_by`, `created_at_utc`. |
| `wp_infocontainer_inspections` | `id`, `container_id`, `identifier_snapshot`, `iso_code_snapshot`, `inspection_type`, `inspected_at_utc`, `observations`, `status`, `created_by`, `created_at_utc`. |

`identifier` tiene un índice único; `container_id` está indexado. Cada inspección exige un contenedor existente. El servidor copia sus valores de `identifier` e `iso_code` a los snapshots históricos, asigna la autoría y fija `status` en `draft`.

La instalación usa `dbDelta()` y es repetible. La versión del esquema se guarda únicamente tras comprobar las tablas y sus índices. También se comprueba la actualización de una instalación que ya está activa. La activación no inserta contenedores ni inspecciones de ejemplo. La desactivación conserva los datos.

## Autenticación y permisos

Todas las rutas requieren un usuario autenticado y `current_user_can('manage_infocontainer')`. La capacidad se concede inicialmente al rol administrador durante la activación y actualización del plugin.

Desde la versión `1.3.1` también se registra **Operador de InfoContainer** (`infocontainer_operator`), con únicamente `read` y `manage_infocontainer`. Se crea tanto al activar como al cargar una instalación activa después de reemplazar sus archivos, independientemente del esquema `1.2.0`. No se asigna a ningún usuario automáticamente ni se cambian sus roles. La comprobación repetida no escribe si los permisos ya están correctos y conserva el acceso de los administradores.

`manage_infocontainer` permite las operaciones actuales del plugin, incluidas escrituras, fotografías y solicitudes de IA: **el operador no es un rol de solo lectura**. No obtiene permisos para administrar WordPress, plugins o usuarios. Si otro componente ya creó el mismo slug con capacidades adicionales, se devuelve `infocontainer_operator_role_conflict` (`503`) sin sobrescribirlo ni reasignar usuarios; un administrador debe revisar ese conflicto. La guía de actualización y creación de cuenta está en [docs/rol-operador.md](docs/rol-operador.md).

Los usuarios autorizados comparten los datos: `created_by` registra quién creó cada registro, pero no limita la lectura al autor. Esta etapa no incluye aislamiento por empresa.

- Desde WordPress: autenticación por cookies y cabecera `X-WP-Nonce` con un nonce para `wp_rest`.
- Desde clientes externos: autenticación nativa de WordPress mediante contraseña de aplicación y HTTPS. Usa el nombre de usuario y la contraseña de aplicación como Basic Auth.
- Una solicitud anónima recibe `401`; un usuario autenticado sin la capacidad recibe `403`.

El plugin no implementa un login ni guarda credenciales. No uses la contraseña normal del usuario como contraseña de aplicación. WordPress y otros plugins pueden imponer restricciones adicionales a la autenticación nativa.

## Contrato de la API

Base: `https://<URL_DEL_SITIO>/wp-json/infocontainer/v1`.

Los POST reciben objetos JSON con `Content-Type: application/json`. Se rechazan campos desconocidos, incluidos `id`, `created_by`, fechas de creación, snapshots y `status`. Los IDs de las rutas deben ser enteros positivos.

| Método | Ruta | Resultado |
| --- | --- | --- |
| `POST` | `/containers` | Crea un contenedor y devuelve el objeto con `201`. |
| `GET` | `/containers` | Devuelve una lista de contenedores con `200`. |
| `GET` | `/containers/{id}` | Devuelve un contenedor con `200`. |
| `POST` | `/containers/{id}/inspections` | Crea una inspección del contenedor con `201`. |
| `GET` | `/containers/{id}/inspections` | Devuelve su historial con `200`. |
| `GET` | `/inspections/{id}` | Devuelve una inspección con `200`. |

No hay endpoints para actualizar o eliminar registros en esta etapa.

### Crear un contenedor

| Campo del cliente | Regla |
| --- | --- |
| `identifier` | Obligatorio, texto. Normaliza mayúsculas, espacios y guiones. Formato: tres letras, `U`, seis dígitos de serie y un dígito de control ISO 6346 válido. |
| `iso_code` | Opcional, texto; si falta o se envía `null` o `""`, queda vacío. Si se indica, normaliza mayúsculas, espacios y guiones y exige cuatro caracteres alfanuméricos. |

Se conservan ceros iniciales y no se sustituyen letras por números. La validación del identificador no demuestra su existencia ni registro en Aduana. El código ISO es independiente; esta etapa no deduce dimensiones, características ni funcionamiento de refrigeración.

Ejemplo de solicitud; `CSQU3054383` cumple el formato y control, sin implicar que este plugin haya verificado un contenedor real:

```json
{
  "identifier": "csqu 305438-3",
  "iso_code": "22g1"
}
```

Ejemplo de respuesta `201`; IDs, autor y fecha son ilustrativos:

```json
{
  "id": 10,
  "identifier": "CSQU3054383",
  "iso_code": "22G1",
  "created_by": 3,
  "created_at_utc": "2026-10-13T14:00:00Z"
}
```

### Crear una inspección

| Campo del cliente | Regla |
| --- | --- |
| `inspection_type` | Obligatorio: `inventory` (inventario), `sale` (venta), `receipt` (recepción) o `delivery` (entrega). |
| `inspected_at` | Opcional, fecha ISO 8601 con segundos y zona explícita, como `Z` o `-05:00`. Acepta fracción de segundo; almacena y devuelve precisión de segundos. El servidor la convierte a UTC. Si falta, usa su hora actual; un `null` explícito se rechaza. |
| `observations` | Opcional, texto plano, vacío por defecto. Conserva saltos de línea, elimina etiquetas HTML y admite como máximo 10.000 caracteres Unicode de entrada, contados antes de sanear el texto. |

Ejemplo para `POST /containers/10/inspections`:

```json
{
  "inspection_type": "receipt",
  "inspected_at": "2026-10-13T09:30:00-05:00",
  "observations": "Recepción en patio.\nRevisar sello de puerta derecha."
}
```

Ejemplo de respuesta `201`; `09:30:00-05:00` se expresa como `14:30:00Z`:

```json
{
  "id": 20,
  "container_id": 10,
  "identifier_snapshot": "CSQU3054383",
  "iso_code_snapshot": "22G1",
  "inspection_type": "receipt",
  "inspected_at_utc": "2026-10-13T14:30:00Z",
  "observations": "Recepción en patio.\nRevisar sello de puerta derecha.",
  "status": "draft",
  "created_by": 3,
  "created_at_utc": "2026-10-13T14:00:00Z"
}
```

Todas las fechas de respuesta usan ISO 8601 con `Z`. Los campos `id`, `container_id` y `created_by` se devuelven como números enteros.

### Listas y búsqueda

Ambas listas admiten `page` (por defecto `1`) y `per_page` (por defecto `20`, de `1` a `100`). Devuelven un array de objetos, ordenado por `id` descendente, con estas cabeceras:

- `X-WP-Total`: número total de registros de la consulta.
- `X-WP-TotalPages`: número total de páginas.

`GET /containers` admite además `identifier`, una búsqueda exacta tras la misma normalización y validación usada al crear. Una búsqueda válida sin coincidencias devuelve una lista vacía. No es una búsqueda parcial.

```text
GET /containers?page=1&per_page=20
GET /containers?identifier=CSQU3054383
GET /containers/10/inspections?page=1&per_page=20
```

### Errores

| HTTP | Situación |
| --- | --- |
| `400` | Parámetros, formato, control ISO 6346, fechas, longitud o campos enviados inválidos. |
| `401` | Falta autenticación. |
| `403` | Usuario autenticado sin `manage_infocontainer`. |
| `404` | Contenedor o inspección inexistente. |
| `409` | Identificador normalizado duplicado, protegido también por el índice único. |
| `500` | Fallo interno; no se exponen SQL ni detalles de la base de datos. |

Los errores usan la estructura REST de WordPress: `code`, `message` y `data.status`. El cliente debe comprobar el estado HTTP y puede usar `code` para distinguir el error.

## Activar y comprobar en LocalWP

1. Inicia el sitio `infocontainer` en Local y abre su administración WordPress.
2. Abre **Plugins**, localiza **InfoContainer**, comprueba la versión `1.3.1` y pulsa **Activar**. Si ya está activo, abre una página de WordPress para ejecutar la comprobación de actualización.
3. En Local, selecciona el sitio y comprueba su dominio y HTTPS. Confía en su certificado desde la opción **Trust/Confiar** de SSL. Usa ese dominio exacto en los ejemplos. Si TLS falla, resuelve la confianza del certificado; no desactives su validación.
4. Abre **Database → Open Adminer** o la herramienta de base de datos disponible en tu versión de Local. Comprueba las dos tablas con el prefijo real, las claves primarias, el índice único de `identifier` y el índice de `container_id`.
5. En **Usuarios → Perfil** de un administrador, crea una contraseña de aplicación con un nombre identificable, como `InfoContainer pruebas Local`. Guárdala fuera del repositorio. Comprueba que WordPress ofrece esta función en el sitio.
6. Ejecuta los ejemplos PowerShell de abajo con el dominio y nombre de usuario correspondientes. Conserva los IDs mostrados: los datos de prueba permanecen en la base de datos.
7. Desactiva y reactiva InfoContainer en **Plugins**. Repite los GET de los IDs capturados y comprueba que siguen existiendo las dos inspecciones, que no se duplicaron y que el número de tablas no cambió.

También puedes usar **Open Site Shell** de Local. Desde la raíz de WordPress, estos comandos comprueban sintaxis y estado sin instalar dependencias:

```text
php -l wp-content/plugins/infocontainer/infocontainer.php
php -l wp-content/plugins/infocontainer/includes/class-infocontainer-install.php
php -l wp-content/plugins/infocontainer/includes/class-infocontainer-repository.php
php -l wp-content/plugins/infocontainer/includes/class-infocontainer-validator.php
php -l wp-content/plugins/infocontainer/includes/class-infocontainer-rest-api.php
wp plugin status infocontainer
wp option get infocontainer_db_version
wp cap list administrator
wp db prefix
```

La opción debe mostrar `1.2.0` y la lista del rol administrador debe incluir `manage_infocontainer`. `wp cap list infocontainer_operator` debe mostrar únicamente `read` y `manage_infocontainer`. Si prefieres activar por CLI: `wp plugin activate infocontainer`. Tras conocer el prefijo, puedes comprobar índices con `wp db query "SHOW INDEX FROM wp_infocontainer_containers;"`, `wp db query "SHOW INDEX FROM wp_infocontainer_inspections;"` y `wp db query "SHOW INDEX FROM wp_infocontainer_photos;"`, sustituyendo `wp_` por el prefijo real.

La disponibilidad de PHP, WP-CLI, autenticación y certificado depende de Local y de la configuración del sitio. Los comandos documentados son procedimientos de comprobación; no constituyen resultados de pruebas ejecutadas.

## Ejemplos PowerShell para Windows

Ejecuta estos bloques en la misma sesión de Windows PowerShell 5.1. Sustituye los marcadores de URL, usuario, ejecutable PHP de Local y certificado SSL existente del sitio. La contraseña se solicita de forma oculta y no se escribe literalmente en el comando, historial ni plugin. No imprimas `$infoContainerHeaders`.

El helper utiliza PHP y cURL ya incluidos en Local como cliente HTTPS. En este equipo, `Invoke-WebRequest` de PowerShell 5.1 falla al establecer la conexión TLS; este transporte permite ejecutar las mismas solicitudes sin cambiar SSL. Verifica el certificado y el nombre del servidor usando el certificado local existente como CA de confianza, y no sigue redirecciones. La autenticación sigue siendo la nativa de WordPress.

Puedes localizar `php.exe` dentro de `%APPDATA%\Local\lightning-services\php-<VERSION>\bin\win64\` y el certificado del sitio en `%APPDATA%\Local\run\router\nginx\certs\<DOMINIO>.crt`. Elige el PHP configurado para este sitio. El archivo temporal generado a continuación contiene únicamente el código del cliente, sin credenciales; el quinto bloque lo elimina.

### Preparar conexión y funciones

```powershell
$siteUrl = 'https://<URL_DEL_SITIO>'
$userName = '<USUARIO_WORDPRESS_AUTORIZADO>'
$infoContainerPhpExe = '<RUTA_PHP_DE_LOCAL>\php.exe'
$infoContainerCaFile = '<RUTA_CERTIFICADO_SSL_LOCAL>.crt'
if (-not (Test-Path -LiteralPath $infoContainerPhpExe -PathType Leaf) -or
    -not (Test-Path -LiteralPath $infoContainerCaFile -PathType Leaf)) {
    throw 'Comprueba las rutas del PHP de Local y del certificado SSL existente.'
}
$infoContainerPhpSource = @'
<?php
$input = json_decode(file_get_contents('php://stdin'), true);
if (!is_array($input) || parse_url($input['url'], PHP_URL_SCHEME) !== 'https'
    || !is_file($input['ca'])) {
    fwrite(STDERR, "URL HTTPS o certificado no valido.\n");
    exit(1);
}
$headers = array('Accept: application/json');
foreach ($input['headers'] as $name => $value) {
    if (strpos($name . $value, "\r") !== false || strpos($name . $value, "\n") !== false) {
        fwrite(STDERR, "Cabecera no valida.\n");
        exit(1);
    }
    $headers[] = $name . ': ' . $value;
}
$responseHeaders = array();
$curl = curl_init($input['url']);
$options = array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST => $input['method'],
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_CAINFO => $input['ca'],
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_HEADERFUNCTION => function ($handle, $line) use (&$responseHeaders) {
        $parts = explode(':', $line, 2);
        if (count($parts) === 2) {
            $responseHeaders[trim($parts[0])] = trim($parts[1]);
        }
        return strlen($line);
    },
);
if (strtoupper($input['method']) === 'HEAD') {
    $options[CURLOPT_NOBODY] = true;
}
if (!empty($input['file_path'])) {
    if (!is_file($input['file_path']) || !is_readable($input['file_path'])) {
        fwrite(STDERR, "Archivo de subida no disponible.\n");
        exit(1);
    }
    $fields = $input['upload_fields'];
    $fields['file'] = new CURLFile($input['file_path'], '', basename($input['file_path']));
    $options[CURLOPT_POSTFIELDS] = $fields;
} elseif ($input['body'] !== null) {
    $options[CURLOPT_POSTFIELDS] = $input['body'];
}
curl_setopt_array($curl, $options);
$body = curl_exec($curl);
if ($body === false) {
    fwrite(STDERR, 'Fallo de transporte HTTPS, codigo cURL ' . curl_errno($curl) . ".\n");
    exit(1);
}
$reply = array(
    'status' => (int) curl_getinfo($curl, CURLINFO_HTTP_CODE),
    'headers' => $responseHeaders,
    'body' => $body,
);
if (!empty($input['download']) && $reply['status'] === 200) {
    $reply['binary_base64'] = base64_encode($body);
    $reply['body'] = '';
}
echo json_encode($reply);
'@
$infoContainerPhpClient = [IO.Path]::GetTempFileName()
[IO.File]::WriteAllText($infoContainerPhpClient, $infoContainerPhpSource, [Text.UTF8Encoding]::new($false))
$applicationPassword = Read-Host '<CONTRASENA_DE_APLICACION>' -AsSecureString
$plainPassword = [System.Net.NetworkCredential]::new('', $applicationPassword).Password
$encodedAuth = [Convert]::ToBase64String(
    [Text.Encoding]::UTF8.GetBytes($userName + ':' + $plainPassword)
)
$infoContainerHeaders = @{
    Authorization = 'Basic ' + $encodedAuth
    Accept = 'application/json'
}
$plainPassword = $null
$encodedAuth = $null
$apiBase = $siteUrl.TrimEnd('/') + '/wp-json/infocontainer/v1'

function Invoke-InfoContainer {
    param(
        [string] $Method = 'GET',
        [Parameter(Mandatory = $true)] [string] $Route,
        [hashtable] $Data,
        [hashtable] $RequestHeaders = $infoContainerHeaders,
        [string] $FilePath,
        [string] $DownloadPath
    )
    $headers = @{} + $RequestHeaders
    $jsonBody = $null
    if ($null -ne $Data -and -not $FilePath) {
        $headers['Content-Type'] = 'application/json; charset=utf-8'
        $jsonBody = ConvertTo-Json -InputObject $Data -Depth 5 -Compress
    }
    $inputPayload = ConvertTo-Json -InputObject @{
        url = $apiBase + $Route
        method = $Method
        headers = $headers
        body = $jsonBody
        ca = $infoContainerCaFile
        file_path = $FilePath
        upload_fields = $(if ($FilePath) { $Data } else { @{} })
        download = [bool] $DownloadPath
    } -Depth 8 -Compress
    $phpExtensions = Join-Path (Split-Path -Parent $infoContainerPhpExe) 'ext'
    $processInfo = [Diagnostics.ProcessStartInfo]::new()
    $processInfo.FileName = $infoContainerPhpExe
    $processInfo.Arguments = '-n -d "extension_dir=' + $phpExtensions +
        '" -d extension=curl -d extension=openssl "' + $infoContainerPhpClient + '"'
    $processInfo.UseShellExecute = $false
    $processInfo.CreateNoWindow = $true
    $processInfo.RedirectStandardInput = $true
    $processInfo.RedirectStandardOutput = $true
    $processInfo.RedirectStandardError = $true
    $processInfo.StandardOutputEncoding = [Text.UTF8Encoding]::new($false)
    $processInfo.StandardErrorEncoding = [Text.UTF8Encoding]::new($false)
    $process = [Diagnostics.Process]::new()
    $process.StartInfo = $processInfo
    try {
        $null = $process.Start()
        $outputTask = $process.StandardOutput.ReadToEndAsync()
        $errorTask = $process.StandardError.ReadToEndAsync()
        $inputBytes = [Text.Encoding]::UTF8.GetBytes($inputPayload)
        $process.StandardInput.BaseStream.Write($inputBytes, 0, $inputBytes.Length)
        $process.StandardInput.Close()
        [Array]::Clear($inputBytes, 0, $inputBytes.Length)
        $inputPayload = $null
        if (-not $process.WaitForExit(45000)) {
            $process.Kill()
            throw 'El cliente HTTPS superó el tiempo de espera.'
        }
        if ($process.ExitCode -ne 0 -or -not [string]::IsNullOrWhiteSpace($errorTask.Result)) {
            throw 'Falló el cliente HTTPS. Comprueba PHP, conectividad y confianza del certificado.'
        }
        $reply = ConvertFrom-Json -InputObject $outputTask.Result
    } finally { $process.Dispose() }
    $responseHeaders = @{}
    foreach ($header in $reply.headers.PSObject.Properties) {
        $responseHeaders[$header.Name] = $header.Value
    }
    $rawBody = $reply.body
    $payload = $null
    if ($DownloadPath -and [int] $reply.status -eq 200) {
        [IO.File]::WriteAllBytes($DownloadPath, [Convert]::FromBase64String($reply.binary_base64))
    }
    if (-not [string]::IsNullOrWhiteSpace($rawBody)) {
        $payload = ConvertFrom-Json -InputObject $rawBody
        if ($rawBody.TrimStart().StartsWith('[')) {
            if ($null -eq $payload) { $payload = @() }
            elseif ($payload -isnot [array]) { $payload = @($payload) }
        }
    }
    [pscustomobject] @{ Status = [int] $reply.status; Headers = $responseHeaders; Data = $payload }
}

function Assert-InfoContainerStatus {
    param($Reply, [int] $Expected, [string] $Label)
    if ($Reply.Status -ne $Expected) {
        throw ($Label + ': HTTP esperado ' + $Expected + ', recibido ' + $Reply.Status)
    }
    Write-Host ($Label + ': HTTP ' + $Reply.Status)
}

function Assert-InfoContainerValue {
    param([bool] $Condition, [string] $Label)
    if (-not $Condition) { throw ('Comprobación fallida: ' + $Label) }
    Write-Host ($Label + ': OK')
}

function New-InfoContainerTestIdentifier {
    # Prefijo sintético para pruebas. Esto no verifica existencia ni registro real.
    $serial = Get-Random -Minimum 0 -Maximum 1000000
    $body = 'TSTU' + $serial.ToString('D6')
    $letterValues = @{
        A=10; B=12; C=13; D=14; E=15; F=16; G=17; H=18; I=19
        J=20; K=21; L=23; M=24; N=25; O=26; P=27; Q=28; R=29
        S=30; T=31; U=32; V=34; W=35; X=36; Y=37; Z=38
    }
    $sum = 0
    for ($position = 0; $position -lt 10; $position++) {
        $character = [string] $body[$position]
        if ($letterValues.ContainsKey($character)) {
            $value = $letterValues[$character]
        } else {
            $value = [int]::Parse($character)
        }
        $sum += $value * [Math]::Pow(2, $position)
    }
    $checkDigit = ([int] $sum % 11) % 10
    $body + $checkDigit
}

function New-InfoContainerTestContainer {
    param([string] $IsoCode = '')
    for ($attempt = 0; $attempt -lt 10; $attempt++) {
        $identifier = New-InfoContainerTestIdentifier
        $inputIdentifier = $identifier.Substring(0, 4).ToLowerInvariant() + ' ' +
            $identifier.Substring(4, 6) + '-' + $identifier.Substring(10, 1)
        $reply = Invoke-InfoContainer -Method POST -Route '/containers' -Data @{
            identifier = $inputIdentifier
            iso_code = $IsoCode
        }
        if ($reply.Status -eq 409) { continue }
        Assert-InfoContainerStatus $reply 201 'Crear contenedor de prueba'
        Assert-InfoContainerValue ($reply.Data.identifier -eq $identifier) 'Identificador normalizado'
        return $reply.Data
    }
    throw 'No se pudo obtener un identificador de prueba disponible.'
}
```

### Crear y consultar registros de prueba

Este bloque crea dos contenedores con prefijo sintético `TSTU` y dos inspecciones identificadas por `INFOCONTAINER_ETAPA1_TEST`. Los IDs se muestran inmediatamente después de cada creación para conservarlos incluso si una comprobación posterior falla.

```powershell
$container = New-InfoContainerTestContainer -IsoCode ''
Write-Host ('Contenedor de prueba: id=' + $container.id + ', identifier=' + $container.identifier)
Assert-InfoContainerValue ($container.iso_code -eq '') 'ISO desconocido vacío'

$secondContainer = New-InfoContainerTestContainer -IsoCode '22 g-1'
Write-Host ('Segundo contenedor: id=' + $secondContainer.id + ', identifier=' + $secondContainer.identifier)
Assert-InfoContainerValue ($secondContainer.iso_code -eq '22G1') 'ISO normalizado'

$duplicate = Invoke-InfoContainer -Method POST -Route '/containers' -Data @{
    identifier = $container.identifier.ToLowerInvariant()
}
Assert-InfoContainerStatus $duplicate 409 'Identificador duplicado'

$currentDigit = [int]::Parse($container.identifier.Substring(10, 1))
$wrongIdentifier = $container.identifier.Substring(0, 10) + (($currentDigit + 1) % 10)
$wrongCheck = Invoke-InfoContainer -Method POST -Route '/containers' -Data @{
    identifier = $wrongIdentifier
}
Assert-InfoContainerStatus $wrongCheck 400 'Dígito de control incorrecto'

$observations = "INFOCONTAINER_ETAPA1_TEST recepción`nSegunda línea de observación."
$firstInspectionReply = Invoke-InfoContainer -Method POST -Route (
    '/containers/' + $container.id + '/inspections'
) -Data @{
    inspection_type = 'receipt'
    inspected_at = '2026-10-13T09:30:00-05:00'
    observations = $observations
}
Assert-InfoContainerStatus $firstInspectionReply 201 'Primera inspección'
$firstInspection = $firstInspectionReply.Data
Write-Host ('Primera inspección: id=' + $firstInspection.id)
Assert-InfoContainerValue ($firstInspection.inspected_at_utc -eq '2026-10-13T14:30:00Z') 'Fecha convertida a UTC'
Assert-InfoContainerValue ($firstInspection.observations -eq $observations) 'Saltos de línea conservados'
Assert-InfoContainerValue ($firstInspection.status -eq 'draft') 'Estado asignado por servidor'
Assert-InfoContainerValue ($firstInspection.identifier_snapshot -eq $container.identifier) 'Snapshot del identificador'
Assert-InfoContainerValue ($firstInspection.iso_code_snapshot -eq '') 'Snapshot de ISO vacío'

$secondInspectionReply = Invoke-InfoContainer -Method POST -Route (
    '/containers/' + $container.id + '/inspections'
) -Data @{
    inspection_type = 'delivery'
    observations = 'INFOCONTAINER_ETAPA1_TEST entrega; fecha actual del servidor.'
}
Assert-InfoContainerStatus $secondInspectionReply 201 'Segunda inspección'
$secondInspection = $secondInspectionReply.Data
Write-Host ('Segunda inspección: id=' + $secondInspection.id)
Assert-InfoContainerValue ($secondInspection.inspected_at_utc -match 'Z$') 'Fecha omitida devuelta en UTC'

$containerRead = Invoke-InfoContainer -Route ('/containers/' + $container.id)
Assert-InfoContainerStatus $containerRead 200 'Consultar contenedor'
$inspectionRead = Invoke-InfoContainer -Route ('/inspections/' + $firstInspection.id)
Assert-InfoContainerStatus $inspectionRead 200 'Consultar inspección'
$history = Invoke-InfoContainer -Route ('/containers/' + $container.id + '/inspections')
Assert-InfoContainerStatus $history 200 'Consultar historial'
Assert-InfoContainerValue (@($history.Data).Count -eq 2) 'Historial con dos inspecciones'
Assert-InfoContainerValue (@($history.Data)[0].id -eq $secondInspection.id) 'Historial ordenado por ID descendente'
Assert-InfoContainerValue ([int] (@($history.Headers['X-WP-Total'])[0]) -eq 2) 'Total del historial'

$identifierQuery = [Uri]::EscapeDataString($container.identifier.ToLowerInvariant())
$search = Invoke-InfoContainer -Route ('/containers?identifier=' + $identifierQuery)
Assert-InfoContainerStatus $search 200 'Buscar por identificador'
Assert-InfoContainerValue (@($search.Data).Count -eq 1) 'Coincidencia exacta'
Assert-InfoContainerValue (@($search.Data)[0].id -eq $container.id) 'ID encontrado'

$pageOne = Invoke-InfoContainer -Route '/containers?page=1&per_page=1'
$pageTwo = Invoke-InfoContainer -Route '/containers?page=2&per_page=1'
Assert-InfoContainerStatus $pageOne 200 'Primera página'
Assert-InfoContainerStatus $pageTwo 200 'Segunda página'
Assert-InfoContainerValue (@($pageOne.Data).Count -eq 1) 'Un elemento por página'
Assert-InfoContainerValue (@($pageTwo.Data).Count -eq 1) 'Segunda página con un elemento'
Assert-InfoContainerValue (@($pageOne.Data)[0].id -gt @($pageTwo.Data)[0].id) 'Orden estable descendente'
Assert-InfoContainerValue ([int] (@($pageOne.Headers['X-WP-Total'])[0]) -ge 2) 'Total de contenedores'
Assert-InfoContainerValue ([int] (@($pageOne.Headers['X-WP-TotalPages'])[0]) -ge 2) 'Total de páginas'

[pscustomobject] @{
    ContainerId = $container.id
    ContainerIdentifier = $container.identifier
    SecondContainerId = $secondContainer.id
    SecondContainerIdentifier = $secondContainer.identifier
    FirstInspectionId = $firstInspection.id
    SecondInspectionId = $secondInspection.id
} | Format-List
```

### Rechazos y permisos

```powershell
foreach ($field in @('id', 'created_by', 'created_at_utc', 'identifier_snapshot', 'iso_code_snapshot', 'status')) {
    $body = @{ inspection_type = 'inventory' }
    $body[$field] = 'VALOR_DEL_CLIENTE'
    $reply = Invoke-InfoContainer -Method POST -Route (
        '/containers/' + $container.id + '/inspections'
    ) -Data $body
    Assert-InfoContainerStatus $reply 400 ('Campo calculado rechazado: ' + $field)
}

$invalidDate = Invoke-InfoContainer -Method POST -Route (
    '/containers/' + $container.id + '/inspections'
) -Data @{ inspection_type = 'inventory'; inspected_at = '2026-10-13T09:30:00' }
Assert-InfoContainerStatus $invalidDate 400 'Fecha sin zona horaria rechazada'

$tooLong = Invoke-InfoContainer -Method POST -Route (
    '/containers/' + $container.id + '/inspections'
) -Data @{ inspection_type = 'inventory'; observations = ('x' * 10001) }
Assert-InfoContainerStatus $tooLong 400 'Observación superior al límite'

$invalidPage = Invoke-InfoContainer -Route '/containers?per_page=101'
Assert-InfoContainerStatus $invalidPage 400 'Límite de paginación'

# Usa IDs que hayas comprobado inexistentes en este sitio.
$missingContainerId = 2147483647
$missingInspectionId = 2147483647
$missingContainer = Invoke-InfoContainer -Route ('/containers/' + $missingContainerId)
Assert-InfoContainerStatus $missingContainer 404 'Contenedor inexistente'
$missingInspection = Invoke-InfoContainer -Route ('/inspections/' + $missingInspectionId)
Assert-InfoContainerStatus $missingInspection 404 'Inspección inexistente'
$missingHistory = Invoke-InfoContainer -Route ('/containers/' + $missingContainerId + '/inspections')
Assert-InfoContainerStatus $missingHistory 404 'Historial de contenedor inexistente'
$missingCreation = Invoke-InfoContainer -Method POST -Route (
    '/containers/' + $missingContainerId + '/inspections'
) -Data @{ inspection_type = 'inventory' }
Assert-InfoContainerStatus $missingCreation 404 'Inspección sin contenedor existente'

$anonymous = Invoke-InfoContainer -Route '/containers' -RequestHeaders @{ Accept = 'application/json' }
Assert-InfoContainerStatus $anonymous 401 'Acceso anónimo'
```

Para comprobar `403`, prepara en WordPress un usuario de prueba cuyo rol no tenga `manage_infocontainer`, por ejemplo suscriptor, y genera su contraseña de aplicación. Este bloque solicita esa contraseña por separado y no cambia los encabezados del administrador:

```powershell
$unauthorizedUser = '<USUARIO_SIN_MANAGE_INFOCONTAINER>'
$unauthorizedPassword = Read-Host '<CONTRASENA_DE_APLICACION_USUARIO_SIN_PERMISO>' -AsSecureString
$unauthorizedPlain = [System.Net.NetworkCredential]::new('', $unauthorizedPassword).Password
$unauthorizedAuth = [Convert]::ToBase64String(
    [Text.Encoding]::UTF8.GetBytes($unauthorizedUser + ':' + $unauthorizedPlain)
)
$unauthorizedHeaders = @{ Authorization = 'Basic ' + $unauthorizedAuth; Accept = 'application/json' }
$unauthorizedPlain = $null
$unauthorizedAuth = $null
$forbidden = Invoke-InfoContainer -Route '/containers' -RequestHeaders $unauthorizedHeaders
Assert-InfoContainerStatus $forbidden 403 'Usuario autenticado sin capacidad'
$unauthorizedHeaders = $null
```

Tras desactivar y reactivar el plugin, comprueba persistencia en la misma sesión:

```powershell
$afterReactivation = Invoke-InfoContainer -Route ('/containers/' + $container.id + '/inspections')
Assert-InfoContainerStatus $afterReactivation 200 'Historial tras reactivar'
Assert-InfoContainerValue ([int] (@($afterReactivation.Headers['X-WP-Total'])[0]) -eq 2) 'Inspecciones conservadas sin duplicación'
Assert-InfoContainerValue (@($afterReactivation.Data).id -contains $firstInspection.id) 'Primera inspección conservada'
Assert-InfoContainerValue (@($afterReactivation.Data).id -contains $secondInspection.id) 'Segunda inspección conservada'

# Al terminar la sesión de prueba, descarta las variables de autenticación.
$infoContainerHeaders = $null
$applicationPassword = $null
$unauthorizedPassword = $null
if (Test-Path -LiteralPath $infoContainerPhpClient) {
    Remove-Item -LiteralPath $infoContainerPhpClient -Force
}
```

Los bloques crean datos únicamente cuando los ejecutas. No hay endpoint de eliminación en esta etapa; registra los IDs para identificar los datos de prueba. No declares superada una comprobación hasta ejecutarla y obtener el resultado esperado. Si no puedes probar HTTPS, contraseñas de aplicación o el usuario sin permiso en tu entorno, registra esas comprobaciones como pendientes.

## Verificación realizada en este sitio LocalWP

El 13 de octubre de 2026 se ejecutaron estas comprobaciones sobre WordPress `7.1.3` y MariaDB `10.11.18`:

- Sintaxis de los cinco archivos PHP: correcta con PHP `7.4` y `8.5`.
- Validador: 333 aserciones correctas en cada versión de PHP, con funciones WordPress simuladas en un archivo temporal externo al plugin. Cubren ISO 6346, normalización, ceros iniciales, fechas imposibles, conversión UTC, límites Unicode y campos no permitidos.
- Integración mediante WP-CLI y WordPress real, ejecutada con PHP `7.4`: 72 comprobaciones correctas de instalación, ausencia de datos de ejemplo en activación, creación, consultas, duplicados, historial, sanitización real, fechas, permisos, paginación, desactivación/reactivación y actualización activa.
- Casos adicionales en WordPress real: siete comprobaciones correctas de JSON inválido, fecha `null`, ID de ruta inválido, intento de sobrescribir ID por query, desbordamiento de paginación y respuesta genérica `500` ante una consulta a una tabla inexistente. No se alteró ninguna tabla existente para simular ese fallo.
- HTTPS real contra el servidor Local con PHP `8.5`: 21 comprobaciones correctas de autenticación nativa, usuario sin permiso, cookies y nonce, y revocación de contraseña de aplicación. El cliente PHP verificó cadena/certificado y nombre del servidor usando el certificado local como CA de confianza; no se desactivó la validación SSL.
- En la entrega inicial, los cinco bloques PowerShell pasaron únicamente el análisis sintáctico. La ejecución HTTPS completa y sus correcciones se registran en el apartado siguiente.

Los registros de prueba permanecen identificados así:

| Contenedor ID | Identificador sintético | ISO | Inspecciones ID |
| --- | --- | --- | --- |
| `1` | `TSTU0003076` | Vacío | `1`, `2` (recepción y entrega). |
| `3` | `TSTU0013074` | `22G1` | `3`, `4` (inventario y venta). |

Sus observaciones comienzan con `INFOCONTAINER_ETAPA1_TEST`. El usuario temporal de prueba ID `2`, sus contraseñas de aplicación y la sesión temporal se eliminaron al finalizar. El plugin quedó activo, con esquema `1.0.0`, dos contenedores y cuatro inspecciones. No se modificó `wp-config.php`, no se instalaron dependencias y no se hicieron commits ni push.

La comprobación específica de WordPress `6.0` queda pendiente para una instalación separada. La secuencia completa PowerShell y la creación simultánea se completaron como se describe a continuación.

## Verificación completa PowerShell y concurrencia

El 13 de octubre de 2026 se ejecutaron realmente los cinco bloques anteriores, en la misma sesión de Windows PowerShell `5.1`, sustituyendo únicamente los marcadores y la entrada interactiva de las credenciales temporales por valores en memoria. Todas las solicitudes usaron HTTPS, el certificado existente y autenticación nativa con contraseñas de aplicación de WordPress.

Se corrigió el helper de los ejemplos: el cliente .NET de `Invoke-WebRequest` no completaba la conexión TLS en este equipo, por lo que se utiliza PHP `8.5` y cURL incluidos en Local, con validación de certificado y hostname habilitada. Los secretos se transmiten por stdin UTF-8 y no se escriben en archivos ni argumentos. Se corrigió también el manejo de arrays JSON en PowerShell `5.1`, para conservar tanto listas de varios elementos como listas vacías sin anidar arrays.

| Bloque | Resultado real |
| --- | --- |
| 1. Conexión y funciones | Configuración, autenticación y helper preparados correctamente. |
| 2. Creación y consulta | Dos contenedores y dos inspecciones: `201`. Duplicado: `409`; control ISO 6346 incorrecto: `400`. Consultas, búsqueda exacta, historial, UTC, snapshots y paginación: correctos, con `200`. |
| 3. Rechazos y permisos | Campos calculados, fecha sin zona, longitud y paginación: `400`; registros y padre inexistentes: `404`; solicitud anónima: `401`. |
| 4. Usuario sin capacidad | Usuario autenticado con contraseña de aplicación, sin `manage_infocontainer`: `403`. |
| 5. Persistencia | Desactivación y reactivación reales. Historial: `200`, con ambas inspecciones conservadas y sin duplicación. |

La prueba simultánea utilizó el identificador nuevo `TSTU9259454`, validado mediante ISO 6346 y comprobado inexistente antes de crear. Dos procesos PowerShell independientes esperaron una barrera común; la diferencia entre sus marcas de inicio fue `1,3127 ms`:

| Proceso | HTTP | Resultado |
| --- | --- | --- |
| `33908` | `409` | Error público `infocontainer_duplicate_identifier`, con `data.status = 409`. |
| `24340` | `201` | Contenedor ID `10`. |

Una consulta preparada a la base de datos confirmó exactamente **un registro** para `TSTU9259454`. Se revisaron ambas respuestas: no incluyen SQL, errores del motor, stack traces ni rutas internas. La consulta previa sin coincidencias también comprobó que el helper devuelve una lista vacía correctamente.

Todos los nuevos datos de esta verificación se conservaron, incluidos los creados durante el intento que detectó el problema de arrays del helper:

| Contenedor ID | Identificador | ISO | Inspecciones ID | Procedencia |
| --- | --- | --- | --- | --- |
| `4` | `TSTU6356661` | Vacío | `5`, `6` | Intento previo a corregir el manejo de arrays. |
| `5` | `TSTU4181517` | `22G1` | Ninguna | Intento previo a corregir el manejo de arrays. |
| `7` | `TSTU8687150` | Vacío | `7`, `8` | Secuencia completa corregida. |
| `8` | `TSTU9023833` | `22G1` | Ninguna | Secuencia completa corregida. |
| `10` | `TSTU9259454` | Vacío | Ninguna | Creación simultánea. |

Las inspecciones nuevas son recepción y entrega y mantienen la marca `INFOCONTAINER_ETAPA1_TEST`. Los contenedores originales ID `1`, `3` y sus inspecciones ID `1`–`4` se compararon campo por campo con la captura anterior a estas pruebas: permanecen intactos. El plugin quedó activo.

Los usuarios temporales ID `3`, `4` y `5`, junto con las contraseñas de aplicación temporales de cada intento, se eliminaron. La contraseña de aplicación utilizada con el administrador fue revocada en cada intento sin eliminar su cuenta ni otras credenciales. No se modificó código PHP del plugin, `wp-config.php`, certificados ni configuración SSL; tampoco se instalaron dependencias ni se hicieron commits o push.

No quedan pendientes los cinco bloques ni la concurrencia. El transporte nativo de `Invoke-WebRequest` continúa fallando en este equipo; no se alteró su configuración de seguridad para forzarlo. WordPress `6.0` se verificará posteriormente en otra instalación.

## Etapa 2: fotografías privadas

### Diagnóstico y prueba administrativa de almacenamiento (1.3.4)

La prueba corregida trata `Photo_Storage::open()` como un recurso: usa `fstat()`, lee el contenido y calcula el hash, cierra siempre el recurso y distingue fallo de lectura de fallo de limpieza.

La pantalla **Herramientas → Diagnóstico de InfoContainer** es solo para administradores (`manage_options`). Su consulta normal no crea carpetas ni cambia permisos. La versión 1.3.3 añade una acción POST separada, protegida por nonce, que acepta únicamente el PNG sintético `artifacts/infocontainer-prueba-sin-datos-reales.png` (SHA-256 `738058ab3581c712288647f79befee81cc359f52cc1377884423f270131bd8c6`). La acción reutiliza la validación y el almacenamiento reales, crea la raíz si hace falta, escribe un archivo nuevo, verifica tamaño/hash, lo elimina y confirma la limpieza. No crea contenedores, inspecciones, adjuntos de Medios ni registros de fotografías.

Esta prueba comprueba almacenamiento del servidor. No demuestra subida desde Android, descargas REST ni privacidad HTTP completa.

El contrato, categorías, límites, migración y tres ejemplos PowerShell de fotografías están en [docs/etapa-2.md](docs/etapa-2.md). Los tres bloques se ejecutaron realmente en LocalWP usando el helper HTTPS de este README, ampliado con `-FilePath` para multipart y `-DownloadPath` para guardar bytes originales.

La versión actual del plugin es `1.2.0`, con esquema `1.1.0`. La actualización añade `infocontainer_photos` e índices por inspección y UUID sin borrar ni reconstruir las tablas anteriores. Sus operaciones de escritura utilizan bloqueos InnoDB de la inspección para comprobar que sigue en `draft`.

Raíz privada utilizada: `C:/Users/MUCACRAN/Local Sites/infocontainer/app/infocontainer-private`. Se comprobó que nginx sirve `app/public`; los archivos están en su carpeta hermana privada y no pertenecen al repositorio Git. Para otro servidor, configura `INFOCONTAINER_PRIVATE_ROOT` o el filtro `infocontainer_private_root` con una raíz absoluta fuera del document root y de aliases públicos. No se modificó `wp-config.php`.

Pruebas reales realizadas en esta entrega:

- Migración al esquema `1.1.0`; comparación campo por campo de los siete contenedores y ocho inspecciones anteriores: intactos. Regresiones de consulta y validación ISO 6346 de la etapa 1: correctas.
- Los tres bloques PowerShell de la documentación completaron alta JPEG/PNG/WebP (`201`), listado y consulta (`200`), descarga (`200`), edición de metadatos (`200`), reintento idéntico (`200` sin sobrescribir metadatos), contenido diferente con el mismo UUID (`409`) y eliminación de una fotografía nueva (`200`, consultas posteriores `404`).
- Descarga de los tres formatos: SHA-256 idéntico al original. Cabeceras privadas, MIME, Content-Length y `nosniff` comprobados.
- Imágenes corruptas, ejecutable disfrazado, JPEG con PHP añadido y extensión/MIME incompatible: `415`; archivo vacío: `400`; archivo de 10 MiB más un byte y dimensión superior a 10.000 píxeles: `413`.
- Campos calculados enviados por cliente, vínculo inmutable, descripción de 2.001 caracteres, fecha sin zona y paginación inválida: `400`. Recursos inexistentes mantienen error JSON `404`.
- Metadatos y archivos sin autenticación: `401`; usuario autenticado sin capacidad: `403`. Subir, editar y eliminar con la inspección nueva temporalmente en estado no draft: `409`; descarga autorizada aún disponible. La inspección de prueba se restauró a `draft`.
- Dos procesos PowerShell independientes, liberados mediante una barrera común, subieron simultáneamente JPEG con un mismo UUID: `201` y `200`, ambos con fotografía ID `5`. Consulta SQL: un registro para ese UUID. El almacenamiento final contiene cuatro registros, cuatro archivos con hashes originales y cero archivos descartados o pendientes en `trash`.
- Raíz configurada dentro de public: `503`, sin crear directorios; clave con traversal: rechazada. URL directa anónima hacia la carpeta privada: `404`, sin devolver la imagen.
- 14 comprobaciones de filesystem y seis con junctions reales de Windows por versión PHP `7.4`/`8.5`: correctas. Las junctions hacia archivos externos se rechazaron y los bytes externos permanecieron intactos; todo el árbol de prueba se retiró.
- Sintaxis de los ocho archivos PHP correcta en `7.4` y `8.5`. La revisión detectó y corrigió la interacción del streaming con `_fields`; su descarga devuelve `200` y conserva SHA-256. HEAD devuelve `200` con longitud y sin cuerpo. `_envelope` produce un error JSON con estado interno `400`, envuelto por WordPress en HTTP `200`, según su comportamiento nativo.

Datos nuevos conservados:

| Recurso | ID | Detalle |
| --- | --- | --- |
| Contenedor | `12` | `TSTU4855933`, ISO vacío. |
| Inspección | `9` | Recepción, `INFOCONTAINER_ETAPA2_TEST`, estado final `draft`. |
| Fotografía JPEG | `1` | Metadatos editados; original preservado. |
| Fotografía PNG | `2` | Original preservado. |
| Fotografía WebP | `3` | Original preservado. |
| Fotografía del reintento simultáneo | `5` | Un registro y un archivo para UUID `49b16ed1-37c1-474f-83ee-60d3c4fb62f0`. |

La fotografía ID `4` se creó y eliminó exclusivamente para verificar DELETE. Las imágenes de prueba son fixtures de 64 × 48 píxeles, no fotografías de contenedores reales. Usuarios temporales ID `6`, `7`, `8` y `9`, sus contraseñas de aplicación y las contraseñas temporales del administrador se retiraron. La sonda web protegida de diagnóstico y los archivos temporales del transporte HTTPS se retiraron.

Entorno efectivo: nginx `client_max_body_size = 1000M`; PHP web `8.5.1`, `upload_max_filesize = 300M`, `post_max_size = 1000M`, memoria `256M`. Fileinfo, GD, EXIF e Imagick están cargados en web; GD admite JPEG, PNG y WebP. El plugin aplica 10 MiB y el presupuesto de decodificación documentado.

El aviso de Imagick compilado para ImageMagick `1809` y cargando `1810` se reprodujo en CLI usando el php.ini efectivo de Local. No apareció en las respuestas web de diagnóstico/subidas/descargas: todas se procesaron correctamente. No se modificaron DLL, servicios, versión de PHP, configuración SSL ni avisos globales. La solución del entorno es revisar con Local un paquete de PHP cuyas DLL de Imagick e ImageMagick correspondan a la misma compilación; esta entrega utiliza GD y no necesita esa intervención.

Pendientes reales: compatibilidad ejecutada en WordPress `6.0` y filesystem Linux, que requieren entornos separados. No se simularon rechazos previos de nginx de más de 1000M ni fallos de disco/COMMIT. Si falla el unlink final tras el commit de una eliminación, la API devuelve `500` y puede quedar un archivo privado en `trash`, que requiere revisión administrativa; no confirma una eliminación incompleta.
