# Etapa 3: propuestas de IA y revisión humana

InfoContainer 1.3.0 incorpora propuestas de identificación y de informe. Ninguna propuesta crea contenedores, cambia su asociación, sustituye observaciones ni aprueba una inspección. El usuario siempre debe registrar una revisión explícita.

## Configuración

La integración usa solo `https://api.openai.com/v1/responses`, TLS validado, cero redirecciones y `store: false`. El modelo predeterminado es `gpt-5.4-mini`, con `reasoning.effort: none`. Las imágenes privadas se envían como data URLs Base64; no se publican ni se envían credenciales de WordPress.

Configure `INFOCONTAINER_OPENAI_API_KEY` como constante o variable de entorno del proceso PHP de este proyecto. `INFOCONTAINER_OPENAI_MODEL` permite elegir el modelo desde el servidor. Android no puede enviar claves, modelos, endpoints ni prompts.

```php
define( 'INFOCONTAINER_OPENAI_API_KEY', getenv( 'INFOCONTAINER_OPENAI_API_KEY' ) );
define( 'INFOCONTAINER_OPENAI_MODEL', 'gpt-5.4-mini' );
```

El timeout predeterminado es 45 segundos (`infocontainer_ai_timeout`, rango 10–120). La salida se limita a 6.000 tokens (`infocontainer_ai_max_output_tokens`). La cuota predeterminada es 20 ejecuciones por usuario y día UTC; se configura con `INFOCONTAINER_AI_DAILY_QUOTA` o `infocontainer_ai_daily_quota`. Estos ajustes son exclusivamente del servidor.

La identificación conserva el máximo de 10 MiB de la etapa 2. Un análisis admite 12 imágenes y 30 MiB totales, sujeto además a un presupuesto de memoria de 128 MiB. También se aplican los límites reales de nginx, `post_max_size` y `upload_max_filesize`. Si hay más de 12 fotos, `photo_ids` es obligatorio.

## Contratos REST

Todas las rutas exigen autenticación nativa y `manage_infocontainer`.

| Ruta | Entrada | Resultado |
|---|---|---|
| `POST /ai/identifications` | multipart: `file`, `client_request_id` UUID | 201 nueva o 200 idempotente |
| `POST /inspections/{id}/ai-analyses` | JSON: `client_request_id`, `photo_ids` opcional | 201 nueva o 200 idempotente |
| `GET /ai/runs/{id}` | Sin cuerpo | Consulta; nunca llama al proveedor |
| `GET /inspections/{id}/ai-runs` | `page`, `per_page` | Lista y cabeceras `X-WP-Total*` |
| `POST /ai/runs/{id}/reviews` | `client_review_id`, `action`, contenido revisado | 201 nueva o 200 idempotente |

La identificación devuelve texto visible, hasta tres candidatos, código ISO visible, legibilidad y advertencias. El servidor normaliza candidatos y calcula `iso_6346_valid`. Un candidato inválido se conserva como evidencia. La foto previa al registro se elimina al finalizar; el cliente debe conservar su copia para subirla luego a la inspección.

El análisis requiere una inspección `draft`, valida la pertenencia de cada foto y vincula hallazgos con `photo_ids`. Incluye lecturas, daños, asuntos por revisar, zonas documentadas/no evaluables, resumen y borrador. Los ID inventados se rechazan. El servidor añade `identifier_comparison`; una contradicción exige `identification_resolution` al aceptar o editar.

`accepted` y `edited` guardan contenido validado separado de la propuesta. Para identificación, `reviewed_content` contiene `identifier` e `iso_code`. Para análisis, `edited` contiene el esquema completo. `rejected` puede guardar un motivo breve. Ninguna revisión borra otra.

## Salida estructurada y seguridad

Los dos esquemas usan `text.format`, `json_schema`, `strict: true` y `additionalProperties: false`. Lo desconocido se representa como `null`. El cliente recorre todos los elementos `output` y `output_text`; distingue refusal, estado incompleto, JSON inválido, timeout y otros errores. Un HTTP 200 del proveedor solo pasa a `completed` después de validación local.

Texto visible y anotaciones se tratan como datos. No se habilitan herramientas, navegación, Aduana ni BoxTech. Las fotografías no justifican afirmar funcionamiento de refrigeración, prueba de temperatura, hermeticidad, certificación vigente ni registro aduanero. La tabla determinista clasifica nominalmente `22G1`, `42G1`, `45G1`, `22R1` y `45R1`; otros códigos quedan sin clasificar. No se estiman medidas visualmente.

REST no expone claves, Base64, rutas, `storage_key`, cuerpos del proveedor ni snapshots. La base sí guarda modelo, prompt, huellas, propuesta, response ID y `usage`.

## Idempotencia, vigencia y temporales

La clave única es `(operation, client_request_id)`. La misma huella devuelve la ejecución; entradas distintas responden 409. La reserva y cuota se serializan antes de la llamada, sin transacción durante la red. Dos solicitudes simultáneas generan una sola llamada.

Estados: `processing`, `completed`, `failed` y `unknown`; este último representa un timeout incierto. `processing_expires_at_utc` usa 180 segundos, configurable entre 60 y 900 con `infocontainer_ai_processing_ttl`. No hay reenvío automático: un reintento requiere otro UUID.

El snapshot incluye inspección, fotos, hashes y metadatos. GET y revisión recalculan su huella. Un cambio devuelve `stale: true` e impide aceptar o editar. Los temporales usan `ai-temp/` dentro de la raíz privada; se borran en `finally`. Cada identificación limpia como máximo 20 abandonados con más de una hora.

| HTTP | Caso |
|---|---|
| 201 / 200 | Nuevo / idempotente o consulta |
| 400 | Entrada o esquema inválido |
| 401 / 403 / 404 | Autenticación, permiso o recurso |
| 409 | Conflicto, foto ajena, no draft o propuesta desactualizada |
| 413 / 429 | Imágenes o cuota excedidas |
| 422 | Refusal |
| 502 | Error, respuesta incompleta o salida inválida |
| 503 | Configuración ausente |
| 504 | Timeout de resultado desconocido |

## Ejemplos PowerShell ejecutados

Usan `Invoke-InfoContainer` del README con HTTPS, certificado validado y contraseña de aplicación.

```powershell
$identification = Invoke-InfoContainer -Method POST -Route '/ai/identifications' `
    -FilePath $photoPath -Data @{ client_request_id = [Guid]::NewGuid().ToString() }
Assert-InfoContainerStatus $identification 201 'Identificación previa'
```

```powershell
$analysis = Invoke-InfoContainer -Method POST `
    -Route ('/inspections/' + $inspectionId + '/ai-analyses') `
    -Data @{ client_request_id = [Guid]::NewGuid().ToString(); photo_ids = @($photoId) }
Assert-InfoContainerStatus $analysis 201 'Propuesta de análisis'
```

```powershell
$review = Invoke-InfoContainer -Method POST `
    -Route ('/ai/runs/' + $analysis.Data.id + '/reviews') -Data @{
        client_review_id = [Guid]::NewGuid().ToString()
        action = 'accepted'
        identification_resolution = 'Identificador confirmado presencialmente.'
    }
Assert-InfoContainerStatus $review 201 'Revisión humana'
```

## Verificación LocalWP: 13 de octubre de 2026

La migración creó ambas tablas InnoDB, verificó columnas e índices y guardó 1.2.0. Las rutas reales usaron un transporte HTTP simulado desde un MU plugin temporal, sin controles de simulación en REST.

Pasaron: clara, ambigua, ilegible, dígito ISO incorrecto, identificación previa, evidencia ligada, foto ajena 409, ID inventado 502, contradicción, null, aceptación, edición, rechazo, historial, cambio de fuente 409, concurrencia 201/200 con una llamada, falta de clave 503, refusal 422, incompleta 502, JSON inválido 502, error 502, timeout 504, 401 y 403. El uso simulado por respuesta completada fue 101 tokens de entrada, 37 de salida y 138 totales; modelo `gpt-5.4-mini-test-simulated`, response IDs `resp_stage3_sim_*`.

No había clave explícita en el proceso web, así que no se hicieron llamadas reales. WordPress 6.0, Linux y llamadas reales quedan pendientes. La sintaxis se comprobó en PHP 7.4 y 8.5.
