# Corrección de prueba administrativa — 1.3.4

El intento HTTP local de 1.3.3 falló porque `Photo_Storage::open()` devuelve un recurso y el diagnóstico llamó erróneamente a `filesize()` sobre él. La corrección usa `fstat()`, lee el recurso, calcula SHA-256, cierra siempre el recurso y ejecuta la limpieza en `finally`, distinguiendo fallo de lectura e integridad de fallo de limpieza.

La búsqueda local no identificó de forma inequívoca un archivo perteneciente al intento fallido; no se hizo ninguna limpieza general ni se eliminó un archivo ambiguo. La subida HTTP satisfactoria queda pendiente de repetir por Sergio en Local. La sintaxis PHP pasa en los tres archivos modificados; el ZIP 1.3.4 está preparado.

La regresión debe comprobar un recurso real devuelto por `open()`, `fstat()`, hash, cierre y eliminación incluso cuando ocurre una excepción posterior al guardado. También deben repetirse capacidad, nonce, hash de archivo permitido y conservación de fotografías existentes. Esta corrección no certifica subida Android, descargas REST ni privacidad HTTP.
