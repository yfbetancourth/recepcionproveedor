# recepcionproveedor (PrestaShop 9)

## Instalacion
1. Copia la carpeta `recepcionproveedor/` en `modules/` o sube el ZIP desde Modulos > Gestor de modulos.
2. Instala el modulo. Aparece **Compras > Recepciones de proveedor** en el menu lateral.
3. Limpia la cache con `php bin/console cache:clear` o desde Parametros avanzados > Rendimiento.
4. El historial se conserva al desinstalar el modulo. Borra sus tablas manualmente solo cuando ya no necesites esos datos.
5. Asigna permisos en Configuracion avanzada > Equipo > Permisos (fila **Recepciones de proveedor**): consultar para ver, crear para borradores, editar para modificar y confirmar, eliminar para borrar borradores.

## Flujo
Crear borrador > elegir proveedor > ingresar numero y fecha del recibo > agregar productos, cantidades y costos > confirmar para actualizar el stock.

## Interfaz de captura
- La búsqueda de proveedor reproduce el flujo de selección del pedido de PrestaShop: muestra carga y resultados vacíos, presenta tarjetas con detalles y acciones para elegir o cambiar, y permite abrir el alta del proveedor en otra pestaña.
- La búsqueda de productos acepta nombre, referencia, EAN o ID; permite elegir producto/combinación, precarga el costo mayorista y ofrece mensajes de carga, ausencia de resultados y error.
- Se puede navegar por los resultados de ambas búsquedas con las flechas del teclado, seleccionar con Enter y cerrar con Escape.