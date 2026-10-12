<?php
declare(strict_types=1);

namespace PrestaShop\Module\RecepcionProveedor\Service;

use PrestaShop\Module\RecepcionProveedor\Exception\ReceptionException;

/**
 * Confirma una recepción y suma las cantidades al stock disponible.
 *
 * Se usa la conexión legacy (Db) porque StockAvailable escribe a través de ella:
 * así la transacción cubre la actualización de stock y el cambio de estado.
 */
final class ReceptionConfirmer
{
    /**
     * @return int número de líneas aplicadas al stock
     *
     * @throws ReceptionException
     */
    public function confirm(int $idReception, int $idEmployee, int $idShop): int
    {
        if (!\Configuration::get('PS_STOCK_MANAGEMENT')) {
            throw new ReceptionException('La gestión de stock está desactivada en la tienda.');
        }

        $db = \Db::getInstance();
        $table = _DB_PREFIX_ . 'recepcionproveedor';
        $lineTable = _DB_PREFIX_ . 'recepcionproveedor_line';

        $db->execute('START TRANSACTION');
        try {
            // Bloquea la fila: evita confirmar dos veces la misma recepción.
            $rows = $db->executeS(
                'SELECT * FROM `' . $table . '` WHERE id_reception = ' . (int) $idReception
                . ' AND id_shop = ' . (int) $idShop . ' FOR UPDATE',
                true,
                false
            );
            $reception = $rows[0] ?? null;

            if (!$reception) {
                throw new ReceptionException('La recepción no existe.');
            }
            if ('draft' !== $reception['status']) {
                throw new ReceptionException('La recepción ya fue confirmada.');
            }
            if (empty($reception['id_supplier'])) {
                throw new ReceptionException('Selecciona un proveedor antes de confirmar.');
            }
            $supplierExists = $db->getValue(
                'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'supplier` s
                 INNER JOIN `' . _DB_PREFIX_ . 'supplier_shop` ss ON ss.id_supplier = s.id_supplier
                 WHERE s.id_supplier = ' . (int) $reception['id_supplier'] . '
                   AND s.active = 1 AND ss.id_shop = ' . (int) $idShop
            );
            if (!$supplierExists) {
                throw new ReceptionException('El proveedor seleccionado ya no existe.');
            }
            $number = trim((string) $reception['receipt_number']);
            if ('' === $number) {
                throw new ReceptionException('Introduce el número de recibo antes de confirmar.');
            }
            if (empty($reception['receipt_date'])) {
                throw new ReceptionException('Introduce la fecha del recibo antes de confirmar.');
            }

            $duplicated = $db->getValue(
                'SELECT COUNT(*) FROM `' . $table . '`
                 WHERE id_shop = ' . (int) $idShop . '
                   AND id_supplier = ' . (int) $reception['id_supplier'] . '
                   AND receipt_number = \'' . \pSQL($number) . '\'
                   AND status = \'confirmed\'
                   AND id_reception <> ' . (int) $idReception
            );
            if ($duplicated) {
                throw new ReceptionException('Ya existe una recepción confirmada de este proveedor con ese número de recibo.');
            }

            $lines = $db->executeS(
                'SELECT * FROM `' . $lineTable . '` WHERE id_reception = ' . (int) $idReception,
                true,
                false
            );
            if (!$lines) {
                throw new ReceptionException('Añade al menos un producto antes de confirmar.');
            }

            foreach ($lines as $line) {
                $idProduct = (int) $line['id_product'];
                $idPa = (int) $line['id_product_attribute'];
                $quantity = (int) $line['quantity'];

                if ($quantity < 1) {
                    throw new ReceptionException('Todas las cantidades deben ser mayores que cero.');
                }
                if ((float) $line['unit_cost'] < 0) {
                    throw new ReceptionException('Los costos no pueden ser negativos.');
                }
                if ($idPa > 0 && !(int) $db->getValue(
                    'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'product_attribute`
                     WHERE id_product_attribute = ' . $idPa . ' AND id_product = ' . $idProduct
                )) {
                    throw new ReceptionException('Una combinación de producto ya no existe; revisa las líneas antes de confirmar.');
                }

                // Persist the receipt as a positive stock movement for the native stock history.
                if (!\StockAvailable::updateQuantity($idProduct, $idPa, $quantity, $idShop, true, [
                    'id_shop' => $idShop,
                    'id_stock_mvt_reason' => (int) \Configuration::get('PS_STOCK_MVT_INC_REASON_DEFAULT'),
                ])) {
                    $name = \Product::getProductName($idProduct, $idPa ?: null);
                    throw new ReceptionException(sprintf(
                        'No se pudo actualizar el stock de "%s". Comprueba que el producto sigue existiendo.',
                        $name ?: '#' . $idProduct
                    ));
                }
            }

            if (!$db->execute(
                'UPDATE `' . $table . '`
                 SET status = \'confirmed\', date_confirmed = NOW(), date_upd = NOW(),
                     id_employee_confirmed = ' . (int) $idEmployee . '
                 WHERE id_reception = ' . (int) $idReception . ' AND status = \'draft\''
            )) {
                throw new ReceptionException('No se pudo marcar la recepción como confirmada.');
            }

            if (!$db->execute('COMMIT')) {
                throw new ReceptionException('No se pudo guardar la confirmación de la recepción.');
            }

            return count($lines);
        } catch (\Throwable $e) {
            $db->execute('ROLLBACK');
            throw $e;
        }
    }
}
