# Regresiones 1.3.5

La tabla administrativa y la versión 1.3.5 fueron verificadas visualmente por Sergio en Local. Sergio también confirmó la ejecución HTTP normal de almacenamiento en 1.3.4.

El arnés se conserva fuera del document root en `C:/Users/MUCACRAN/infocontainer-test-harness/regression-1.3.5.php`. `wp-load.php` existe, pero el PHP CLI disponible de Local aborta antes de cargar WordPress por falta de `mysqli`. Por tanto no se ejecutaron las regresiones de recurso, excepción, fallo de eliminación ni testigo; no se modificaron datos ni archivos reales.

Para completar, Site Shell debe usar el PHP configurado por Local con `mysqli` habilitado y ejecutar el arnés desde su ruta absoluta. La subida HTTP normal ya está confirmada y no se repite.
