<?php
declare(strict_types=1);

use PrestaShop\PrestaShop\Adapter\SymfonyContainer;
use PrestaShop\Module\RecepcionProveedor\Install\Installer;

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

class RecepcionProveedor extends Module
{
    public function __construct()
    {
        $this->name = 'recepcionproveedor';
        $this->tab = 'administration';
        $this->version = '1.0.1';
        $this->author = 'Tu empresa';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => _PS_VERSION_];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->trans('Recepción de proveedor', [], 'Modules.Recepcionproveedor.Admin');
        $this->description = $this->trans('Registra recepciones de mercancía de proveedores y actualiza el stock al confirmarlas.', [], 'Modules.Recepcionproveedor.Admin');
    }

    public function isUsingNewTranslationSystem(): bool
    {
        return true;
    }

    public function install(): bool
    {
        if (!parent::install()) {
            return false;
        }

        return $this->getInstaller()->install($this);
    }

    public function uninstall(): bool
    {
        return $this->getInstaller()->uninstall() && parent::uninstall();
    }

    private function getInstaller(): Installer
    {
        return new Installer();
    }

    /** El botón "Configurar" del listado de módulos lleva a la página del módulo. */
    public function getContent(): void
    {
        $router = SymfonyContainer::getInstance()->get('router');
        Tools::redirectAdmin($router->generate('recepcionproveedor_index'));
    }

}
