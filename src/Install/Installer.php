<?php
declare(strict_types=1);

namespace PrestaShop\Module\RecepcionProveedor\Install;

/** Installs the module tables and its Back Office tabs. */
final class Installer
{
    private const TAB_PARENT = 'AdminRecepcionProveedorParent';
    private const TAB_PAGE = 'AdminRecepcionProveedor';

    public function install(\Module $module): bool
    {
        return $this->installDb()
            && $this->installTabs($module->name);
    }

    public function uninstall(): bool
    {
        return $this->uninstallTabs();
    }

    private function installTabs(string $moduleName): bool
    {
        $languages = \Language::getLanguages(false);

        $parentId = (int) \Tab::getIdFromClassName(self::TAB_PARENT);
        if (!$parentId) {
            $parent = new \Tab();
            $parent->active = 1;
            $parent->class_name = self::TAB_PARENT;
            $parent->route_name = '';
            $parent->module = $moduleName;
            $parent->id_parent = 0;
            $parent->icon = 'local_shipping';
            foreach ($languages as $language) {
                $parent->name[(int) $language['id_lang']] = 'Compras';
            }
            if (!$parent->add()) {
                return false;
            }
            $parentId = (int) $parent->id;
        }

        if (!\Tab::getIdFromClassName(self::TAB_PAGE)) {
            $page = new \Tab();
            $page->active = 1;
            $page->class_name = self::TAB_PAGE;
            $page->route_name = 'recepcionproveedor_index';
            $page->module = $moduleName;
            $page->id_parent = $parentId;
            foreach ($languages as $language) {
                $page->name[(int) $language['id_lang']] = 'Recepciones de proveedor';
            }
            if (!$page->add()) {
                return false;
            }
        }

        return true;
    }

    private function uninstallTabs(): bool
    {
        foreach ([self::TAB_PAGE, self::TAB_PARENT] as $className) {
            $id = (int) \Tab::getIdFromClassName($className);
            if ($id) {
                $tab = new \Tab($id);
                if (!$tab->delete()) {
                    return false;
                }
            }
        }

        return true;
    }

    private function installDb(): bool
    {
        $prefix = _DB_PREFIX_;
        $engine = _MYSQL_ENGINE_;

        $queries = [
            "CREATE TABLE IF NOT EXISTS `{$prefix}recepcionproveedor` (
                `id_reception` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_shop` INT UNSIGNED NOT NULL,
                `id_supplier` INT UNSIGNED NULL,
                `receipt_number` VARCHAR(64) NULL,
                `receipt_date` DATE NULL,
                `notes` TEXT NULL,
                `payment_method` VARCHAR(128) NULL,
                `id_order_state` INT UNSIGNED NULL,
                `status` VARCHAR(16) NOT NULL DEFAULT 'draft',
                `id_employee` INT UNSIGNED NOT NULL,
                `id_employee_confirmed` INT UNSIGNED NULL,
                `date_add` DATETIME NOT NULL,
                `date_upd` DATETIME NOT NULL,
                `date_confirmed` DATETIME NULL,
                PRIMARY KEY (`id_reception`),
                KEY `idx_status_shop` (`status`, `id_shop`),
                KEY `idx_supplier_number` (`id_supplier`, `receipt_number`)
            ) ENGINE={$engine} DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$prefix}recepcionproveedor_line` (
                `id_line` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_reception` INT UNSIGNED NOT NULL,
                `id_product` INT UNSIGNED NOT NULL,
                `id_product_attribute` INT UNSIGNED NOT NULL DEFAULT 0,
                `quantity` INT UNSIGNED NOT NULL,
                `unit_cost` DECIMAL(20,6) NOT NULL DEFAULT 0,
                PRIMARY KEY (`id_line`),
                UNIQUE KEY `uniq_reception_product` (`id_reception`, `id_product`, `id_product_attribute`),
                CONSTRAINT `fk_{$prefix}recepcionproveedor_line_reception`
                    FOREIGN KEY (`id_reception`) REFERENCES `{$prefix}recepcionproveedor` (`id_reception`)
                    ON DELETE CASCADE
            ) ENGINE={$engine} DEFAULT CHARSET=utf8mb4",
        ];

        foreach ($queries as $query) {
            if (!\Db::getInstance()->execute($query)) {
                return false;
            }
        }

        return true;
    }
}