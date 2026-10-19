# Operador de InfoContainer — versión 1.3.1

InfoContainer es desarrollado por Mucacrán S.A.S. Esta actualización añade un rol dedicado sin conceder administración general de WordPress. No incorpora red Android, sincronización o cambios en la autenticación nativa.

## Permisos y actualización

El rol **Operador de InfoContainer**, slug `infocontainer_operator`, tiene únicamente:

| Capacidad | Uso |
|---|---|
| `read` | Acceso básico de WordPress y perfil propio. |
| `manage_infocontainer` | Las operaciones disponibles en la API de InfoContainer. |

El plugin comprueba identidad WordPress y `manage_infocontainer`. No necesita `manage_options`, `edit_users`, `promote_users`, `edit_posts` ni `upload_files`: sus fotografías se gestionan en rutas propias protegidas. Las contraseñas de aplicación dependen de HTTPS y las políticas nativas del sitio, no de que la cuenta sea administradora.

**Este rol no es exclusivamente de lectura.** Puede utilizar las operaciones actuales del plugin, incluidas escrituras, fotografías y solicitudes de IA. Los registros son compartidos entre los usuarios autorizados; `created_by` no proporciona aislamiento por persona o empresa. Una contraseña de aplicación tampoco restringe sus permisos a un único endpoint. Si el servidor tiene OpenAI configurado, invocar los POST de IA puede consumir cuota y enviar imágenes al proveedor; crear el rol o la cuenta no ejecuta esas operaciones.

El registro de permisos se llama al activar y en la carga normal de una instalación activa, incluso con el esquema ya actualizado. Reemplazar los archivos basta; no es necesario desactivar/reactivar. Se mantiene `DB_VERSION = 1.2.0`; esta versión no añade ni altera tablas. La migración de una versión antigua del esquema sigue el comportamiento existente del instalador.

Se conserva `manage_infocontainer` en el rol administrador. No se cambia el rol predeterminado ni se asigna o reasigna ningún usuario. Las cargas posteriores no escriben roles si ya están correctos. Un rol compatible al que le falte una de las dos capacidades se completa sin tocar otras definiciones.

Si el slug ya existe con cualquier otra capacidad, el plugin devuelve `infocontainer_operator_role_conflict` (`503`) sin retirar permisos ni reemplazar el rol. Ese error impide operar por la API hasta que un administrador revise la colisión. No resolverlo asignando el rol administrador a la cuenta dedicada. Desactivar el plugin conserva roles y datos, conforme al comportamiento existente; no hay eliminación automática de cuentas.

## Actualización manual en producción por Sergio

1. Antes de actualizar, conservar un respaldo de la base de datos, archivos del sitio y carpeta privada de fotografías. Esta carpeta puede estar fuera de `public_html` y no estar incluida en el respaldo habitual del hosting. No moverla al directorio público.
2. Entrar en WordPress como administrador. En **Plugins → Añadir nuevo → Subir plugin**, seleccionar el ZIP `infocontainer-1.3.1.zip` suministrado. Comprobar que corresponde al plugin existente InfoContainer y elegir **Reemplazar el actual con el subido**. No borrar ni desinstalar el plugin y no instalar una segunda copia con otro nombre. WordPress admite el reemplazo por ZIP, con comparación de versiones, desde 5.5: [referencia oficial](https://make.wordpress.org/core/2020/07/29/miscellaneous-developer-focused-changes-in-wordpress-5-5/).
3. Confirmar en **Plugins** la versión `1.3.1` y que InfoContainer sigue activo. Abrir una página del panel; esa carga registra el rol si faltaba, sin depender de una reactivación. Si Hostinger mantiene código PHP anterior en caché, seguir el procedimiento del hosting para renovar esa caché y volver a comprobar la versión, sin cambiar datos ni autenticación.
4. En **Usuarios → Añadir nuevo**, comprobar que la lista de roles ofrece **Operador de InfoContainer**. Si no aparece, detener la creación y comprobar versión, actividad del plugin y posibles errores; no sustituirlo por Administrador. No activar registro público ni cambiar el rol predeterminado. [Gestión nativa de usuarios](https://wordpress.org/documentation/article/users-add-new-screen/).
5. Crear una cuenta nueva dedicada, con datos y contraseña normal gestionados localmente por Sergio, y elegir **Operador de InfoContainer**. No cambiar el rol de cuentas existentes. Guardar los secretos en su gestor de contraseñas; no enviarlos al chat, adjuntos, repositorio o registros.
6. Entrar con esa cuenta o editar su perfil desde la administración. En **Perfil → Contraseñas de aplicación**, indicar un nombre reconocible, por ejemplo `InfoContainer Android Sergio`, y generar una contraseña. WordPress la muestra una sola vez: guardarla directamente en el gestor de contraseñas. Usar para el futuro cliente el nombre de usuario y esta contraseña de aplicación, separados de la contraseña habitual. Puede revocarse individualmente en ese perfil. [Documentación oficial](https://developer.wordpress.org/advanced-administration/security/application-passwords/).
7. Si no aparece la sección, verificar HTTPS reconocido por WordPress y restricciones del hosting o plugins de seguridad. No forzar la disponibilidad desactivando TLS ni añadir otra autenticación. Confirmar primero el hostname y destino HTTPS final sin enviar credenciales. El mecanismo esperado sigue siendo Basic Auth nativo con contraseña de aplicación sobre HTTPS: [autenticación REST oficial](https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/).
8. En una comprobación posterior controlada, introducir la credencial en un cliente local mediante entrada enmascarada no registrada y solicitar únicamente `GET /wp-json/infocontainer/v1/containers?per_page=1&_fields=id`. Se espera `200` y un array, incluso vacío. No poner secretos en URL, argumentos de comandos, historial, capturas o logs; no seguir redirecciones con Authorization ni ejecutar rutas IA. Sin credencial se espera `401`; con una cuenta ya existente sin capacidad, `403`. No crear cuentas adicionales solo para esa última prueba.

La actualización alojada, su autenticación y la privacidad de archivos deben verificarse en producción por Sergio. Una captura de `infocontainer_authentication_required` con `data.status: 401` acredita respuesta del endpoint en ese navegador; no demuestra autenticación, resuelve el TLS de otro cliente ni acredita conexión Android → WordPress.

## Verificación de desarrollo y continuidad

La evidencia de esta entrega y los bloqueos se registran en el apartado de actualización al final de `diagnostico-conexion-android-wordpress-2026-10-16.md` del repositorio de desarrollo. Ese diagnóstico local, pruebas y archivos del IDE no se incluyen en el ZIP instalable.

Las pruebas aisladas de permisos se ejecutan con `php tests/permissions.php`; emplean dobles de WordPress, sin base de datos ni HTTP. Un resultado `401/403/permitido` de esas pruebas acredita la lógica de permisos, no autenticación por contraseña de aplicación. La prueba REST interna en WordPress, si se ejecuta, también debe distinguirse de una solicitud HTTP real.

La conexión Android sigue pendiente de un encargo específico. No se modifica su código en esta versión. Las fotografías y las propuestas de IA requieren revisión humana y no certifican hermeticidad, funcionamiento ni condición estructural.
