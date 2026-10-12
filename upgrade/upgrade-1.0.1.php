<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_1($module)
{
    $table = _DB_PREFIX_ . 'recepcionproveedor';
    $db = Db::getInstance();
    $columns = $db->executeS('SHOW COLUMNS FROM `' . bqSQL($table) . '`');
    if (false === $columns) {
        return false;
    }
    $existing = array_column($columns, 'Field');
    if (!in_array('payment_method', $existing, true)
        && !$db->execute('ALTER TABLE `' . bqSQL($table) . '` ADD `payment_method` VARCHAR(128) NULL AFTER `notes`')) {
        return false;
    }
    if (!in_array('id_order_state', $existing, true)
        && !$db->execute('ALTER TABLE `' . bqSQL($table) . '` ADD `id_order_state` INT UNSIGNED NULL AFTER `payment_method`')) {
        return false;
    }

    $preparationState = (int) Configuration::get('PS_OS_PREPARATION');
    if ($preparationState > 0) {
        $db->execute('UPDATE `' . bqSQL($table) . '` SET `id_order_state` = ' . $preparationState . ' WHERE `id_order_state` IS NULL');
    }

    return true;
}
