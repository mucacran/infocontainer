# Diagnóstico previo Android → WordPress alojado

InfoContainer, desarrollado por Mucacrán S.A.S. Fecha: 16 de octubre de 2026, zona America/Guayaquil (UTC−05:00). Inspección y consultas de conectividad aproximadamente entre 22:14 y 22:17; redacción posterior en la misma sesión.

**Resultado: diagnóstico realizado con bloqueos documentados. La conexión Android → WordPress no está establecida ni probada.** El contrato local permite una primera lectura existente, pero Android aún no tiene red implementada. Desde este equipo falló la validación TLS del alojamiento; no se obtuvo el índice REST ni una respuesta autenticada. No se puede confirmar la versión alojada, sus rutas ni su privacidad efectiva.

Solo se añadió este informe. No se modificaron código funcional, datos, fotografías, cuentas, roles, credenciales, configuración, certificados ni dependencias. No hubo compilaciones, instalaciones Android, commits, push, despliegues, mutaciones HTTP ni llamadas reales a OpenAI. Las propuestas siguientes requieren otro encargo para su implementación.

## 1. Rutas, versiones y preservación

| Elemento | Resultado observado | Estado |
|---|---|---|
| Plugin local real | `C:/Users/MUCACRAN/Local Sites/infocontainer/app/public/wp-content/plugins/infocontainer` | Realizada |
| Plugin / esquema | `1.3.0` / `1.2.0`; mínimos declarados WordPress `6.0`, PHP `7.4` | Realizada, lectura de código |
| WordPress local | `7.1.3`, declarado en `app/public/wp-includes/version.php`; no se cargó WordPress | Realizada, lectura de archivo |
| Git plugin | HEAD `e110773`; inicialmente `?? .idea/` y `?? infoCOntainer.zip`; archivos versionados sin diferencias | Realizada |
| Android real | `C:/proyectosMucacran/infocontainerapp`; HEAD `08a064b`, rama `main...origin/main`, limpio al principio y al cierre de su revisión | Realizada |
| Android declarado | `com.mucacran.infocontainer`, versionName `1.0`, versionCode `1`; minSdk `26`, targetSdk `36`, compileSdk `36.1`; Java `11` | Realizada, lectura de configuración |
| Herramientas Android | AGP `9.2.1`, Kotlin `2.2.10`, Compose BOM `2026.02.01`, Gradle `9.4.1` | Realizada, sin ejecutarlas |
| Copia alojada | Sergio informó activación en `https://loinde.com.ec`, versión mostrada `1.3.0`; no corroborada en esta sesión | Pendiente |

No se encontraron `AGENTS.md` aplicables en los repositorios y ancestros examinados. Se leyeron el README y `docs/etapa-2.md`, `docs/etapa-3.md` del plugin, y el README Android. Se conservaron los cambios existentes; no se abrió ni reemplazó el ZIP. En consultas posteriores este dejó de aparecer en el directorio/estado Git, sin ninguna operación de escritura, movimiento o eliminación del diagnóstico sobre él; su disponibilidad final no está confirmada.

Fuentes de versión: `infocontainer.php:6–8,20`; `includes/class-infocontainer-install.php:9–11`; Android `app/build.gradle.kts:8–19,33–35`, `gradle/libs.versions.toml:2–10`, `gradle/wrapper/gradle-wrapper.properties:5`.

## 2. Contrato y autenticación encontrados en código

Base del plugin local: `/wp-json/infocontainer/v1`. **Esta tabla describe código local; no rutas confirmadas en hosting.**

Todas las rutas siguientes exigen identidad WordPress y `manage_infocontainer`. Sin identidad, el plugin devuelve `401 infocontainer_authentication_required`; con identidad sin capacidad, `403 infocontainer_forbidden`. Errores anteriores a su callback pueden proceder del núcleo WordPress o de otro componente y tener otros códigos.

| Método / ruta relativa | Entradas | Respuesta prevista por código | Errores relevantes |
|---|---|---|---|
| `GET /containers` | `page` positivo (defecto `1`); `per_page` positivo, máximo `100` (defecto `20`); `identifier` opcional, ISO 6346 válido y búsqueda exacta. Admite parámetros globales como `_fields`. | `200`, array, incluso `[]`; objetos con `id`, `identifier`, `iso_code`, `created_by`, `created_at_utc`. Cabeceras `X-WP-Total`, `X-WP-TotalPages`; orden ID descendente. | `400 infocontainer_invalid_request`, `infocontainer_invalid_identifier`, `infocontainer_invalid_identifier_check_digit`, `infocontainer_invalid_unexpected_field`; `401/403`; `500 infocontainer_internal_error`. |
| `GET /photos/{id}` | ID positivo; sin cuerpo. | `200`, metadatos: `id`, `inspection_id`, `client_photo_id`, `category`, `description`, `original_filename`, `mime_type`, `byte_size`, `width`, `height`, `sha256`, `captured_at_utc`, autores y fechas de creación/actualización. Fechas UTC `Z`, captura puede ser null. No incluye `storage_key` ni ruta física. | `400 infocontainer_invalid_photo_request`; `401/403`; `404 infocontainer_photo_not_found` o `infocontainer_inspection_not_found`; `500 infocontainer_photo_internal_error`. |
| `GET /photos/{id}/file` | ID positivo; sin cuerpo. No admite `_envelope`. | `200`, bytes JPEG/PNG/WebP; `Content-Type`, `Content-Length`, nombre de descarga sintético, `Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0`, `X-Content-Type-Options: nosniff`. | Los anteriores; `500 infocontainer_file_unavailable` si no puede leer el original; `503 infocontainer_private_storage_unavailable`. `_envelope` provoca error interno `400`, aunque WordPress puede envolverlo en HTTP `200`. |
| `POST /ai/identifications` — NO ejecutado | Multipart: exactamente un `file` y `client_request_id` UUID. Imagen validada y temporal privada. | `201` ejecución nueva completada; `200` reintento idempotente. Objeto de ejecución/propuesta que exige confirmación humana. | `400 infocontainer_ai_invalid_request`; errores de imagen `400/413/415/500/503`; `409 infocontainer_ai_idempotency_conflict`; `429 infocontainer_ai_quota`; proveedor `422/502/503/504`. |
| `POST /inspections/{id}/ai-analyses` — NO ejecutado | JSON con `client_request_id` UUID y `photo_ids` opcional, positivos, distintos y pertenecientes a la inspección. Si hay más de 12 fotos, exige selección explícita de hasta 12. Requiere `draft`, al menos una foto y 30 MiB totales como máximo, además del presupuesto de memoria. | `201` nueva completada o `200` idempotente; ejecución/propuesta con comparación de identificación; requiere revisión humana. | `400 infocontainer_ai_invalid_request`, incluso al omitir selección con más de 12 fotos; `404 infocontainer_inspection_not_found`; `409 infocontainer_inspection_not_draft`, `infocontainer_ai_photo_mismatch`, conflicto idempotente; `413 infocontainer_ai_image_limit` al seleccionar más de 12 o `infocontainer_ai_image_budget`; cuota/proveedor anteriores. |

Evidencia: `includes/class-infocontainer-rest-api.php:18–44,64–83,164–202,220–260`; `class-infocontainer-repository.php:125–155`; `class-infocontainer-validator.php:27–54,180–217`; `class-infocontainer-photo-rest-api.php:25–46,145–148,218–276,440–474`; `class-infocontainer-photo-repository.php:357–379`; `class-infocontainer-ai-rest-api.php:12–77`; `class-infocontainer-ai-repository.php:11–36`; `class-infocontainer-openai-client.php:20–65`. Estos archivos están en `includes/`.

También existen las rutas de contenedores/inspecciones, listado/subida de fotos por inspección, PATCH/DELETE de fotos, GET de ejecuciones IA y de su listado por inspección, y POST de revisiones. No se utilizaron. No se encontró un login o una ruta de salud propios; no hace falta crearlos.

El formato normal de error es `{code,message,data:{status}}`. IA también puede devolver una ejecución con `error:{code,message}` anidado después de reservarla; un reintento puede devolver HTTP `200` con ejecución fallida/incierta. Este detalle se reserva para una etapa futura y no afecta al GET mínimo.

La lectura propuesta es **`GET /wp-json/infocontainer/v1/containers?per_page=1&_fields=id`**, una vez confirmada en el índice alojado. Comprueba identidad, capacidad y acceso a datos sin necesitar mostrar identificadores, autores o fotografías; una lista vacía válida también acredita el acceso. No modifica borradores ni sincroniza imágenes desde su callback.

La autenticación externa documentada es la nativa de WordPress: usuario y **contraseña de aplicación**, mediante Basic Auth sobre HTTPS; la contraseña habitual del usuario no es el secreto que debe utilizarse. Cookies y nonce REST corresponden a la sesión web. El plugin no autentica por sí mismo ni guarda credenciales. Esto coincide con la [documentación oficial de autenticación REST de WordPress](https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/).

El núcleo local contiene `wp_authenticate_application_password` y sus filtros de disponibilidad (`wp-includes/default-filters.php:518`, `wp-includes/user.php:372,5155–5212`): soporte condicionado a HTTPS/entorno local y filtros, incluso por usuario. **La disponibilidad en el servidor alojado queda pendiente**: no se obtuvo `authentication` del índice ni se accedió al perfil del usuario.

La instalación concede inicialmente `manage_infocontainer` al administrador (`class-infocontainer-install.php:173–183`). No hay aislamiento por autor/empresa: los usuarios con capacidad comparten registros. La misma capacidad permite lecturas, escrituras e IA. Para el siguiente encargo se propone una cuenta dedicada sin administración general, con `read` y `manage_infocontainer`; es el mínimo disponible con este contrato, pero **no constituye una cuenta de solo lectura**. Si se exige esa restricción real, habrá que separar capacidades en un encargo adicional.

## 3. Consultas reales al alojamiento

No se enviaron credenciales. No se siguieron redirecciones automáticamente. No se desactivó la validación de certificado, hostname ni revocación. No se usaron `-k`, contextos sin verificación ni callbacks que aceptaran errores. Las sondas TLS con .NET devolvieron aceptación únicamente si `SslPolicyErrors == None`; ambas fueron rechazadas.

| Fecha local UTC−05:00 | Cliente / método / URL | HTTP / Content-Type / código WordPress | Observación y estado |
|---|---|---|---|
| 2026-10-16 22:15 | Windows curl/Schannel, `GET https://loinde.com.ec/` | Sin respuesta HTTP; tipo y código WP no disponibles; curl `35` | **Fallida**. `CRYPT_E_NO_REVOCATION_CHECK (0x80092012)`. Handshake rechazado. |
| 2026-10-16 22:15 | Python 3.11/OpenSSL, `GET https://loinde.com.ec/` | Sin respuesta HTTP; tipo/código WP no disponibles | **Fallida**. `CERTIFICATE_VERIFY_FAILED: certificate has expired`. |
| 2026-10-16 22:15:38 | Python, `GET https://loinde.com.ec/wp-json/` | Sin respuesta HTTP; tipo/código WP no disponibles | **Fallida**. Mismo error de certificado vencido; no se recibió el índice. |
| 2026-10-16 22:15 | Windows curl, `GET http://loinde.com.ec/` | `301`, `text/html`; no código WP | **Realizada**. `Location: https://loinde.com.ec/`, cabecera `Server: hcdn`. Destino del salto igual en host, cambio a HTTPS. No demuestra una cadena HTTPS final válida. |
| 2026-10-16 22:15–22:16 | Herramienta web externa, apertura `GET https://loinde.com.ec/wp-json/` | No expuso estado HTTP, Content-Type ni código WP | **Fallida**. Herramienta indicó que la URL no era accesible; no permite atribuir causa ni confirmar rutas. |

Dos inspecciones adicionales hicieron solo handshake TLS, sin HTTP, a `loinde.com.ec:443`. La cadena observada por .NET contiene un certificado para `CN=loinde.com.ec` emitido por **Kaspersky Anti-Virus Personal Root Certificate**, con SAN para `loinde.com.ec` y `www.loinde.com.ec`. Las hojas observadas eran vigentes, aproximadamente del 18/04/2026 al 17/04/2027; la raíz mostraba vigencia hasta 2035. El rechazo fue `RemoteCertificateChainErrors`, estado `RevocationStatusUnknown`; no se indicó `RemoteCertificateNameMismatch`.

**Interpretación:** existe evidencia de intermediación TLS de Kaspersky en este equipo. El error de expiración de Python no identifica qué elemento de su cadena falla. Las diferencias entre clientes impiden afirmar que el certificado original del hosting esté vencido o sea válido. Tampoco se validó la cadena pública original. Hace falta repetir una consulta validada desde un entorno de confianza independiente y resolver la cadena/revocación local con el responsable del equipo. No se propone desactivar controles TLS ni cambiar el antivirus como parte de este encargo.

No se obtuvieron errores JSON de WordPress, `401`, `403` o `404` alojados. No hay evidencia para atribuir estos fallos a rutas ausentes, plugin inactivo, WAF, permisos WordPress o credenciales incorrectas. `hcdn` es una cabecera observada en el salto HTTP; no demuestra un bloqueo del CDN. No se obtuvo HTML de error de un endpoint REST.

El salto HTTP apunta a HTTPS en el mismo host, pero **el origen HTTPS definitivo aún no está confirmado** porque el handshake falló antes de recibir sus posibles redirecciones. No se intentó autenticación sin resolverlo.

## 4. Android y requisitos de la primera conexión

La arquitectura encontrada es Kotlin, Compose/Material 3, ViewModel, repositorio SQLite y almacenes de archivos privados. `MainActivity.kt:35–39` conecta repositorio, fotos, ViewModel y recuperación. La BD es versión 2, con migración aditiva v1→v2 (`data/InspectionDatabaseHelper.kt:18–73`); `data/InspectionDraftRepository.kt:38–73,193–209` conserva campos y vínculos fotográficos en transacción y realiza IO fuera de UI.

`data/LocalPhotoStore.kt:41–86` copia originales a `noBackupFilesDir`; `ui/FormRecoveryStore.kt:22–37` usa AtomicFile para recuperación privada. La UI emplea cámara del sistema y selector (`ui/PhotographsSection.kt:131–176`). Esto permite seleccionar imágenes de WhatsApp cuando Android las expone al selector; no prueba acceso a cualquier imagen recibida ni integración directa con WhatsApp. Se mantiene la distinción documentada entre recuperar trabajo y guardarlo.

**Aún faltan red y autenticación:** no hay permiso `INTERNET` en `app/src/main/AndroidManifest.xml`, cliente HTTP, flujo de credenciales ni Keystore implementado en `src/main`. Las dependencias actuales son de AndroidX/Compose y pruebas. No se interpretan las funciones locales existentes como sincronización.

`allowBackup=true`; `backup_rules.xml` y `data_extraction_rules.xml` excluyen `camera_capture`, y los almacenes en `noBackupFilesDir` quedan fuera de copia. La BD sigue respaldable: restaurarla puede recuperar referencias sin originales. Es una decisión pendiente ya documentada; no se cambió la política. Las credenciales nuevas deberán quedar fuera tanto de copia en nube como de transferencia entre dispositivos.

Los archivos Kotlin abreviados anteriores están bajo `app/src/main/java/com/mucacran/infocontainer/`; las reglas, bajo `app/src/main/res/xml/`.

Hay una diferencia de límites para una futura subida: Android admite 30 MiB/foto y 40 MP; el plugin impone 10 MiB, 20 MP nominales, dimensión máxima 10.000 y presupuesto de decodificación 128 MiB (`8 bytes/píxel + 32 MiB`, más límite PHP). Ese presupuesto fija un techo teórico de aproximadamente 12,58 MP, que PHP puede reducir; no equivale al total de archivos permitidos por borrador Android. **No afecta al GET inicial**. Antes de sincronizar fotos se deberá diseñar validación y, si se autoriza, una copia de transferencia compatible, conservando el original.

Las pruebas previas del README Android, incluida recuperación tras force-stop, compilación y Lint, no se repitieron ni se presentan como ejecutadas hoy. El README del plugin registra pruebas locales anteriores; las de IA en `docs/etapa-3.md:93–99` utilizaron transporte simulado. No acreditan llamadas reales ni el hosting actual.

## 5. Privacidad: conclusión local separada del hosting

**Local — revisión estática realizada, privacidad efectiva de ejecución pendiente.**

- `wp-config.php:98–99` fija por defecto `ABSPATH` en `app/public`. La configuración nginx generada `C:/Users/MUCACRAN/AppData/Roaming/Local/run/xHg8L0Mgd/conf/nginx/site.conf:9,82` sirve esa ruta y la transmite como `DOCUMENT_ROOT`. El router de `infocontainer.local` dirige al puerto loopback `10096`.
- La raíz calculada estáticamente es `C:/Users/MUCACRAN/Local Sites/infocontainer/app/infocontainer-private`, carpeta existente hermana de `public`. No aparecieron overrides de constante/filtro en los PHP examinados de `wp-content`, `wp-config.php` o el bootstrap de Local. No se certifica el valor efectivo en runtime sin ejecutarlo.
- No se encontró `alias` hacia esa carpeta en nginx del sitio/router examinados. El recorrido de `app/public` encontró **0 ReparsePoint**; sus ancestros y la carpeta privada tampoco eran symlink/junction. No se examinaron nombres ni contenido de fotos, ni se volcaron configuraciones completas o secretos. No se hizo una auditoría de ACL de Windows ni de otros servicios ajenos a este sitio.
- El almacenamiento resuelve rutas reales y rechaza superposición con `ABSPATH`/`DOCUMENT_ROOT`, enlaces y escape de claves (`class-infocontainer-photo-storage.php:41–69,448–475,501–574`). En Windows verifica acceso pero no impone permisos POSIX: los ACL requieren revisión específica si se necesita evaluar otros usuarios locales.
- La lista explícita de metadatos omite ruta y clave de almacenamiento (`class-infocontainer-photo-rest-api.php:440–466`); las cabeceras privadas de archivo están en `:247–251`. Son garantías del código inspeccionado, no cabeceras observadas por HTTP hoy.

No se llamó `Photo_Storage::root()`, `open()` ni otras rutinas para descubrir rutas: pueden crear carpetas o ajustar permisos. Tampoco se cargó WordPress como sonda local, porque `plugins_loaded → maybe_upgrade()` puede instalar/migrar esquema, modificar opción y conceder capacidad si detecta otra versión (`infocontainer.php:73–78`, `class-infocontainer-install.php:17–20,173–183`).

**Hosting — pendiente.** No hay acceso suministrado a archivos, configuración ni ACL del alojamiento, y TLS impidió consultar su REST. Se desconocen la raíz privada efectiva, docroots/aliases, enlaces y política de caché/CDN alojados. No se puede certificar ausencia de exposición directa con el diseño local ni con URLs supuestas.

El README local identifica fixtures previos de 64×48 píxeles; no se descargaron porque no había credencial de prueba disponible. No se asume que esos IDs existan en hosting. Quedan pendientes, sobre una foto alojada de prueba conocida que Sergio designe: descarga autorizada, rechazo anónimo, rechazo de cuenta sin capacidad si ya existe y repetición de la misma URL sin credenciales después de descargarla autenticado; revisión de cabeceras y ausencia de rutas/claves en respuestas. No se enumeraron IDs, no se subieron fotos y no se interpretó un `404` inexistente como prueba de privacidad.

## 6. Matriz consolidada de comprobaciones

| Entorno / acción | Resultado esperado | Observado / evidencia resumida | Estado |
|---|---|---|---|
| Repositorios: instrucciones, Git y versiones antes de actuar | Identificar copias y conservar trabajo | Rutas/HEAD y versiones de §1; sin diferencias de archivos funcionales | Realizada |
| Plugin: contrato y permisos | Determinar lectura candidata y respuestas | §2; `GET /containers?per_page=1&_fields=id`; autenticación nativa + capacidad | Realizada, estática |
| Android: red y almacenamiento | Determinar preparación real | Datos/fotos locales; sin INTERNET/red/auth; respaldo y límites en §4 | Realizada, estática |
| Hosting: HTTP raíz | Descubrir salto hacia HTTPS | `301 text/html`, Location mismo host HTTPS | Realizada |
| Hosting: TLS HTTPS y hostname | Cadena válida antes de HTTP o credenciales | Rechazos Schannel, OpenSSL y .NET; intermediación local observada | Fallida |
| Hosting: índice REST | `200 application/json`, namespace/rutas y autenticación anunciada | Dos intentos sin índice utilizable; detalle §3 | Fallida |
| Hosting: versión/igualdad de copia | Corroborar versión y código desplegados | No hay acceso/índice ni hashes del alojamiento; captura previa no basta | Pendiente |
| Hosting: GET anónimo containers | `401` JSON según código local | No ejecutado: ruta alojada no descubierta, TLS bloqueado | Pendiente |
| Hosting: GET autenticado mínimo | `200` array, incluso vacío | Sin credencial suministrada por canal local y TLS/origen pendientes | Pendiente |
| Hosting: GET con usuario sin capacidad | `403` JSON según código local | No hay cuenta de prueba disponible; no se crearon cuentas | Pendiente |
| Hosting: contraseñas de aplicación | Disponibles para la cuenta y Basic Auth aceptado | Núcleo local las soporta; disponibilidad/Authorization alojados sin comprobar | Pendiente |
| Local: ruta privada/docroot/enlaces/configuración | Raíz separada sin publicación encontrada | Carpeta hermana, sin alias/reparse points en alcance inspeccionado | Realizada, estática |
| Local: foto, cabeceras y rechazos actuales por HTTP | Bytes correctos, 401/403, no exposición | No se repitieron pruebas históricas ni rutinas con efectos secundarios | Pendiente |
| Hosting: archivo autorizado/anónimo/sin capacidad y caché | Archivo privado en los cuatro casos indicados en §5 | Sin foto conocida alojada, credenciales ni TLS; no se supusieron IDs | Pendiente |
| Android → hosting desde dispositivo | GET autenticado sin cambios locales | No implementado ni ejecutado | Pendiente |
| OpenAI y operaciones de escritura | Cero solicitudes en este encargo | No se invocaron rutas IA ni operaciones de mutación | Realizada: cero |

“Pendiente” significa no ejecutada o no demostrada; “fallida” identifica un intento real que no alcanzó el resultado. Las lecturas de código no son pruebas HTTP.

## 7. Causas, correcciones propuestas y acciones de Sergio

1. **Bloqueo TLS demostrado desde este equipo.** Revisar la cadena/revocación del cliente y obtener una comprobación independiente con validación normal del hostname y cadena pública. Sergio puede solicitar al responsable del equipo/hosting el resultado saneado de esa comprobación: fechas, emisor, estado TLS y redirecciones, sin secretos. No atribuir todavía la expiración al servidor ni desactivar verificaciones para avanzar.
2. **Copia alojada no corroborada.** Sergio debe facilitar una revisión local y controlada del plugin activo (versión, archivos/hashes) y del índice REST después de resolver TLS. No hace falta desplegar ni reemplazar nada para verificarlo. Si aparece `404`, HTML, WAF o pérdida de Authorization, clasificar la respuesta real antes de proponer ajustes; no hay evidencia actual de esos fallos.
3. **Credenciales de prueba no disponibles para este diagnóstico.** No se recibió un canal local seguro ni aparecieron variables dedicadas de diagnóstico con los nombres examinados; no se buscaron contraseñas en archivos personales, navegador o almacenes genéricos. En un siguiente encargo autorizado, Sergio podrá introducir usuario y contraseña de aplicación en un diálogo local enmascarado/entrada interactiva no registrada, tras confirmar el origen HTTPS. No pegarlos en el chat ni ponerlos en URL, comandos, historial, capturas, informes o repositorio. Puede conservar el secreto en su gestor de contraseñas y transferirlo directamente a esa entrada local. No se debe imprimir ni guardar Authorization.
4. **Cuenta mínima pendiente de preparación autorizada.** Usar cuenta dedicada sin administrador y capacidad mínima disponible descrita en §2. Si ya hay una cuenta sin capacidad, utilizarla para el `403`; no crear otra solo para completar esta matriz. La creación de usuario, contraseña de aplicación o cambio de rol no se realizó ni está autorizada por esta etapa.
5. **Privacidad alojada pendiente.** Sergio debe coordinar acceso de lectura a la configuración/docroot/raíz privada/aliases/enlaces/ACL y caché, o aportar un resumen saneado verificado por quien administra el hosting; además designar una foto fixture existente. No enviar fotografías reales innecesarias ni configuraciones completas con secretos. La prueba REST no sustituye esta revisión.
6. **Android carece de red y credenciales.** Implementar únicamente el flujo aislado de §8 en el siguiente encargo. No es un fallo nuevo de los borradores locales.
7. **Documentación operativa desactualizada.** README del plugin `:161` pide versión `1.2.0`, y `:182` espera esquema `1.1.0`; los valores actuales son `1.3.0`/`1.2.0`. Proponer su corrección por separado; no se cambió el README ni se usaron sus ejemplos de escritura como diagnóstico.
8. **Límites fotográficos y respaldo.** Registrar la diferencia de límites y la restauración de BD sin fotos para la futura sincronización. No requieren modificar los datos ni la política de respaldo para esta primera lectura.

No se requiere que Sergio entregue secretos al chat. Los bloqueos anteriores no impidieron terminar las inspecciones independientes de código, configuración y filesystem.

## 8. Propuesta concreta del siguiente encargo

> Implementar en Android una pantalla manual e independiente «Conexión WordPress» que realice exclusivamente una lectura autenticada de InfoContainer, después de completar el diagnóstico TLS, descubrir la ruta alojada y confirmar la cuenta de prueba por un canal local seguro. Conservar borradores, fotos, SQLite y recuperación. No implementar sincronización ni IA.

- **URL:** configurar localmente una URL base HTTPS del sitio, sin usuario/contraseña/query/fragmento, y confirmar separadamente origen y base REST descubiertos. `https://loinde.com.ec/` es el candidato, todavía sin cadena HTTPS final validada. No incrustar un origen definitivo sin comprobarlo. Rechazar redirecciones en la petición autenticada; ante una, detenerse y verificar anónimamente el destino. Nunca reenviar Authorization a otro origen.
- **Credenciales:** formulario local con contraseña enmascarada, sin persistencia por defecto ni copia a estado restaurable. Usar usuario WordPress y contraseña de aplicación. Si el usuario elige conservarla, cifrar con AES-GCM e IV nuevo por escritura mediante una clave no exportable de Android Keystore; guardar solo ciphertext/IV en `noBackupFilesDir`, vinculados al origen. Excluir cualquier almacén alternativo de copia en nube y transferencia. Ante pérdida/invalidez de clave, pedir reingreso y permitir borrar la credencial; sin alternativa en texto claro. Esta es una propuesta de diseño apoyada en [Android Keystore](https://developer.android.com/privacy-and-security/keystore) y las reglas de [Auto Backup](https://developer.android.com/identity/data/autobackup).
- **Red:** añadir `INTERNET`; un cliente HTTPS mínimo, por ejemplo `HttpsURLConnection`, con ejecución IO, cancelación, tiempos límite y respuesta acotada. Mantener validación TLS estándar, sin certificados confiados a ciegas ni HTTP global. No incluir secretos en código, recursos, BuildConfig, APK, SQLite, diarios, backups o logs.
- **Acción:** botón «Probar conexión», una única GET a la ruta confirmada `containers?per_page=1&_fields=id`. Interpretar `200` con array válido como acceso conseguido aunque esté vacío; mostrar solo estado/resultado mínimo. Ningún guardado automático de borrador ni subida de fotos.
- **Errores:** diferenciar URL/DNS/conexión/timeout/TLS; `401` (autenticación rechazada), `403` (permiso insuficiente, confirmar código JSON), `404` REST/ruta, `429`, `5xx`, HTML de intermediario y JSON/tipo inesperados. Mostrar mensajes saneados y permitir reintento manual, sin volcar cuerpos, credenciales o datos de registros en logs.
- **Verificación:** casos locales controlados para estados/error de formato y redirecciones sin filtrar Authorization; después la GET real desde el dispositivo con credenciales introducidas por Sergio. Comprobar que no cambian BD, borradores ni fotos. No repetir suites de cámara/IA/recuperación sin un cambio que lo justifique. No desinstalar ni borrar datos; justificar una instalación debug si llega a ser necesaria.

El éxito de una herramienta HTTP en el PC solo acreditará ese cliente. El criterio para declarar Android conectado será una GET autenticada ejecutada por la app desde el dispositivo, con TLS normal, respuesta válida y sin cambios de datos locales.

## 9. Conteos y resumen de continuidad

- **Intentos dirigidos al REST alojado: 2**, ambos al índice `/wp-json/`: uno mediante Python, abortado en TLS antes de enviar HTTP; otro mediante herramienta web, sin estado HTTP disponible. Son invocaciones de diagnóstico; la herramienta web no expone sus reintentos internos.
- **Respuestas REST utilizables: 0.** Solicitudes dirigidas a rutas `/infocontainer/v1/*`: **0**, porque no se pudo descubrir el contrato alojado con TLS válido.
- Otras consultas al sitio: **3 intentos GET a la raíz**, dos HTTPS fallidos y un HTTP con `301`; además **2 handshakes TLS sin HTTP**. Las tres lecturas de documentación oficial WordPress/Android no son pruebas REST del sitio.
- Solicitudes autenticadas, mutaciones alojadas, subidas/descargas de fotografías y pruebas desde Android: **0**.
- **Solicitudes efectivas a OpenAI generadas por este encargo: 0.** No se ejecutaron identificación, análisis, suites IA ni cambios de su configuración. La clave del proveedor permanece exclusivamente en el servidor por diseño del cliente (`class-infocontainer-openai-client.php:7,20–41,68–70`); no se inspeccionó su valor.

Para continuar: plugin local `1.3.0`, esquema `1.2.0`, contrato y permisos confirmados solo en código; Android local `08a064b` conserva el flujo por fotografías y borradores, sin red todavía. El almacenamiento local está fuera del docroot según configuración inspeccionada, con corroboración runtime pendiente; la privacidad alojada sigue sin demostrar. Primero resolver/corroborar TLS público y local, luego descubrir REST y preparar credenciales locales y foto de prueba conocida. Implementar después únicamente el GET aislado de §8, si se autoriza ese nuevo encargo. No declarar una conexión ya establecida.

Las propuestas e informes derivados de fotografías requieren confirmación/revisión humana. Ni las imágenes ni la IA certifican hermeticidad, funcionamiento o condición estructural. No se incorporó Gemini.

## Actualización de continuidad — rol operador, 16 de octubre de 2026

Esta sección corresponde al encargo posterior autorizado de implementar permisos en desarrollo. Conserva como histórico el diagnóstico anterior; sus conteos no incluyen las comprobaciones nuevas que se detallan aquí.

**Cambio preparado: InfoContainer 1.3.1.** Ubicación confirmada: el mismo plugin de desarrollo en Local WP. Git inicial: `main...origin/main [behind 1]`, con `.idea/` y este diagnóstico sin seguimiento; no se hizo pull, commit, push ni despliegue. No había un rol dedicado definido en el código. El estado persistido inicial de roles no pudo consultarse mientras Local estuvo detenido; no se supuso que un slug preexistente fuera seguro.

Se añadió `infocontainer_operator`, nombre **Operador de InfoContainer**, con exactamente `read` y `manage_infocontainer`. `InfoContainer_Install::register_roles()` es utilizado por `install()` durante activación y por `maybe_upgrade()` cuando el esquema ya está al día. La carga normal de un plugin activo verifica los permisos aunque no haya migración SQL, por lo que reemplazar archivos no exige reactivar. Se mantiene `DB_VERSION = 1.2.0` y la concesión al administrador; no se asignan roles a usuarios ni se cambia el rol predeterminado. Un slug con otras capacidades devuelve un conflicto `503` sin sobrescribirlo: ese error se propaga a REST hasta la revisión administrativa.

Las capacidades bastan para identidad/perfil y las operaciones actuales del plugin, sin administración general. No son exclusivas de lectura y los registros continúan compartidos; no se añadió aislamiento por empresa ni cambió la autenticación nativa.

### Evidencia de esta entrega

| Comprobación | Resultado observado | Estado |
|---|---|---|
| Sintaxis de los dos PHP modificados | Correcta con PHP 7.4.30 y 8.5.1 | Realizada |
| `tests/permissions.php`, dobles sin DB/HTTP | 34/34 aserciones correctas en cada versión PHP: rol exacto, idempotencia, reparación de capacidades, conservación de roles, colisión y permisos REST | Realizada, aislada |
| Primer intento de arranque WordPress | Error de conexión a DB; el puerto Local 10095 no escuchaba. Sergio inició el sitio y se pudo continuar | Fallida inicialmente, resuelta |
| WordPress 7.1.3 / plugin activo 1.3.1 | Rol persistido con exactamente `read: true`, `manage_infocontainer: true`; administrador conserva acceso | Realizada, WordPress real |
| Instalación activa y repetición de `maybe_upgrade()` | Resultado correcto; 0 escrituras de roles durante carga/comprobaciones con estado ya correcto | Realizada, WordPress real |
| Callback de activación `infocontainer_activate()` | Ejecutado directamente y correcto sobre esquema existente; sin desactivar el plugin; 0 consultas DDL, esquema sigue 1.2.0 | Realizada, WordPress real; no simula instalación nueva |
| GET interno anónimo `/infocontainer/v1/containers?per_page=1&_fields=id` | `401 infocontainer_authentication_required` | Realizada, dispatch REST real con actor en memoria |
| Mismo GET, actor sin capacidad | `403 infocontainer_forbidden` | Realizada, dispatch REST real con actor en memoria |
| Mismo GET, actor operador | `200`, array válido, lectura real de repositorio | Realizada, dispatch REST real con actor en memoria |
| Mismo GET, actor administrador | `200`, acceso conservado | Realizada, dispatch REST real con actor en memoria |
| Preservación DB durante verificaciones reales | Huellas y cantidades sin diferencias para las cinco tablas InfoContainer, `users` y `usermeta`; definiciones de roles sin diferencias entre capturas; no se crearon cuentas ni se asignaron roles a usuarios | Realizada |
| Preservación de archivos privados | 10 archivos, 6.159 bytes; manifiesto agregado de rutas/tamaños/SHA-256 sin diferencias. No se imprimieron nombres ni contenidos | Realizada |
| HTTPS local anónimo mediante curl/Schannel | GET intentado sin credenciales ni redirecciones: curl 35, `CRYPT_E_NO_REVOCATION_CHECK`; sin HTTP ni Content-Type | Fallida por TLS |
| HTTPS con contraseña de aplicación | No ejecutada: no hay credencial suministrada para ese canal y persiste el fallo TLS; no se crearon secretos de prueba | Pendiente |
| Producción | No se modificó ni se consultó en este encargo. Sergio reportó captura del endpoint con `infocontainer_authentication_required`, `data.status: 401` | Evidencia de Sergio; autenticación alojada pendiente |
| Android | Repositorio limpio, sin cambios; no implementados red ni autenticación | Sin intervención; conexión pendiente |

Los actores `WP_User` de las comprobaciones REST existían solo en memoria y recibieron capacidades del rol real; no representaban cuentas creadas o autenticadas mediante Basic Auth. Se hicieron dos pasadas de cuatro GET internos (8 dispatches) al ampliar la verificación a activación, y un intento HTTPS local anónimo que no superó TLS. **Esto no demuestra autenticación nativa HTTP**, persistencia de credenciales ni conexión Android → WordPress. No se instaló el ZIP mediante el actualizador web local; se validaron su estructura y correspondencia con los archivos preparados.

Durante el proceso CLI de verificación se bloquearon solicitudes HTTP salientes y DDL como salvaguardas temporales del script, sin alterar la configuración del sitio; los contadores permanecieron en cero. PHP de Local emitió el aviso de versiones incompatibles de Imagick ya descrito en la documentación; no impidió estas pruebas y no se modificó la extensión.

Incidencia de manejo de información: una lectura inicial demasiado amplia de configuración local mostró una credencial de desarrollo en la salida de herramienta. No se reproduce aquí ni se incorporó al código, pruebas, informes o ZIP. No se modificaron credenciales.

**Archivos de esta entrega:** `infocontainer.php`, `includes/class-infocontainer-install.php`, `README.md`, nuevo `docs/rol-operador.md`, nuevo `tests/permissions.php` y esta sección añadida al diagnóstico existente. `.idea/` se conserva. Los archivos funcionales anteriores fuera de esta lista permanecen intactos.

**Paquete:** `C:/Users/MUCACRAN/Local Sites/infocontainer/artifacts/infocontainer-1.3.1.zip`, raíz única `infocontainer/`, acompañado por checksum SHA-256 y manifiesto de archivos. Incluye PHP del plugin, README y documentación de uso. Excluye Git, IDE, pruebas, diagnósticos locales, credenciales/configuración del sitio, fotografías y ZIP anteriores. La versión del paquete es 1.3.1; el esquema permanece 1.2.0.

Siguiente acción de Sergio: seguir `docs/rol-operador.md` para respaldar BD/archivos/carpeta privada, reemplazar el plugin existente desde el panel, comprobar la versión y el rol, crear una cuenta dedicada nueva y generar su contraseña de aplicación en el perfil por un canal local seguro. No utilizar una cuenta administradora como solución permanente ni enviar secretos al chat. Confirmar después una GET HTTPS autenticada desde un cliente con TLS válido; Android requiere aún otro encargo.

**Conteo del nuevo encargo: 0 solicitudes a OpenAI, 0 rutas IA ejecutadas, 0 mutaciones o despliegues en producción, 0 cambios Android, 0 cuentas creadas/reasignadas.** Solo se autorizó y preparó el registro del rol local; no se declara comprobada la autenticación alojada ni la conexión Android.

## Incidente de exposición local — revisión posterior

La salida accidental correspondió a una contraseña de cliente de desarrollo de MariaDB para la cuenta `root`, usada por Local WP. La cuenta se utiliza para la conexión de base de datos del sitio `infoContainer` y aparece también en la configuración de otros dos sitios Local; el valor no es exclusivo de InfoContainer ni se puede rotar de forma aislada. El servidor MariaDB observado acepta esa autenticación en el entorno local y escucha en loopback; no se intentó contra producción ni contra ningún servicio externo.

La dependencia compartida incluye la configuración de cliente de Local, la conexión de WordPress del sitio y los otros sitios Local que usan el mismo valor. No se modificaron credenciales, bases de datos, `wp-config.php`, configuraciones de Local ni servicios. No se imprimen valores, hashes ni contenidos completos de configuración en este informe. La exposición del chat no puede borrarse retroactivamente desde el repositorio; el valor no se copió al código, ZIP, Git, logs ni artefactos entregados.

Estado de corrección: **no rotada**, deliberadamente, porque una sustitución local requeriría coordinar los tres sitios y validar sus procesos; no hay autorización para una interrupción general. La mitigación segura es no reutilizar esta credencial fuera de Local, mantener los servicios enlazados a loopback y, si Sergio necesita sanearla, coordinar una rotación de la credencial compartida con respaldo y actualización conjunta de cada configuración por el panel/soporte de Local. No debe cambiarla editando un único archivo ni usarla en Hostinger.

Verificaciones posteriores: autenticación local al MariaDB de `infoContainer` funcionó; el servidor informó cuentas `root` locales sin mostrar material secreto; se identificaron tres configuraciones de sitios Local que contienen una contraseña de cliente; no se consultó producción. Los datos, usuarios, roles y los 10 archivos privados de InfoContainer conservaron sus huellas. El ZIP `1.3.1` permanece byte a byte válido y no contiene esta credencial ni archivos de configuración.

## Actualización TLS — 17 de octubre de 2026

Sergio informa que actualizó producción a 1.3.1 y creó `infocontainer_android` como operador con contraseña de aplicación privada. Su respuesta anónima 401 en navegador se conserva como evidencia de ese cliente; no acredita autenticación. Este encargo no solicitó ni usó la contraseña.

Se identificaron por certificado **dos fallos locales distintos**. Schannel/.NET recibe una hoja de `loinde.com.ec` emitida por Kaspersky, vigente hasta abril de 2027, que no tiene CDP ni AIA/OCSP; Windows marca `RevocationStatusUnknown` precisamente en esa hoja y rechaza la cadena. Python recibe una hoja diferente, pública de Let's Encrypt YE2, vigente hasta el 23/11/2026. Su contexto original incorpora desde Windows `CA` un ISRG Root X2 cruzado caducado el 15/09/2025 y lo elige en profundidad 3 pese a que el servidor presenta otro X2 cruzado vigente hasta 2032. Por tanto no era la hoja pública del hosting la que había vencido.

El reloj se contrastó con NTP: desfase aproximado de 3,47 segundos, insuficiente para explicar ese vencimiento. No se detectó proxy explícito. No se cambió hora, confianza, antivirus ni configuración global.

Se corrigió el contexto de un utilitario diagnóstico Python fuera del plugin: carga únicamente raíces existentes de Windows `ROOT` para propósito servidor, sin modificar almacenes. Ese handshake comprobó cadena y hostname con la hoja pública. Python sigue sin CRL cargadas; ese éxito **no sustituye la revocación de Schannel** y no se utilizó para enviar HTTP. Verificación offline de la cadena servida: correcta; sustituyendo solo el X2 por el antiguo: error 10 en profundidad 3. No hay evidencia que justifique renovar el certificado en Hostinger.

Después se ejecutó **un único intento GET anónimo**, con curl y todos sus controles intactos, a `/wp-json/infocontainer/v1/containers?per_page=1&_fields=id`, a 14:29:36 UTC−05:00. Resultado: curl 35, `CRYPT_E_NO_REVOCATION_CHECK (0x80092012)`, HTTP `000`, sin tipo de contenido ni JSON; TLS abortó antes de enviar HTTP. El 401 esperado continúa pendiente en ese cliente. No se declara comprobada autenticación ni conexión Android.

Queda propuesta para decisión de Sergio/responsable una excepción de inspección HTTPS de Kaspersky limitada al dominio exacto, conservando toda validación Windows y revocación, o solicitar al fabricante una corrección que permita mantener la inspección. No se ejecutó ninguna de esas acciones ni se probó ignorar errores. Las huellas completas, cadena, vigencias, evidencia y condiciones de la propuesta están en [el informe TLS](</C:/Users/MUCACRAN/Local Sites/infocontainer/artifacts/tls-2026-10-17/diagnostico-tls.md>).

Conteo de este encargo: **5 handshakes de diagnóstico sin HTTP**, **1 intento REST abortado en TLS**, **0 peticiones HTTP REST enviadas**, **0 respuestas REST**, **0 uso de credenciales** y **0 solicitudes a OpenAI**. Se hicieron además dos verificaciones offline de certificados y dos muestras NTP. Los archivos funcionales del plugin, Android, MariaDB y el ZIP 1.3.1 permanecen intactos; solo se añadió esta continuidad y utilitarios/evidencias fuera del directorio público. La corrección de Windows/Kaspersky y el GET 401 permanecen pendientes.
