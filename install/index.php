<?php

use CModule;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\EventManager;

Loc::loadMessages(__FILE__);

if (class_exists('ecomkassa_payti')) {

    return;
}

class ecomkassa_payti extends CModule
{
    public $MODULE_ID = 'ecomkassa.payti';
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME;
    public $MODULE_DESCRIPTION;

    public function __construct()
    {
        $arModuleVersion = [];

        require_once __DIR__ . '/version.php';

        $this->MODULE_VERSION = $arModuleVersion['VERSION'];
        $this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'];
        $this->MODULE_NAME = Loc::getMessage('ECOMKASSA_PAYTI_USER_MODULE_NAME');
        $this->MODULE_DESCRIPTION = Loc::getMessage('ECOMKASSA_PAYTI_USER_MODULE_DESCRIPTION');
        $this->PARTNER_NAME = Loc::getMessage('ECOMKASSA_PAYTI_USER_PARTNER_NAME');
        $this->PARTNER_URI = Loc::getMessage('ECOMKASSA_PAYTI_USER_PARTNER_URI');
    }

    public function DoInstall()
    {
        global $USER;

        if (!$USER->IsAdmin()) {

            return;
        }

        $this->registerHandlers();

        ModuleManager::RegisterModule($this->MODULE_ID);
    }

    public function DoUninstall()
    {
        global $USER;

        if (!$USER->IsAdmin()) {

            return;
        }

        $this->unregisterHandlers();

        ModuleManager::UnregisterModule($this->MODULE_ID);
    }

    public function registerHandlers(): void
    {
        $eventManager = EventManager::getInstance();

        $this->registerEcomkassaPaytiCashboxHandler($eventManager);
    }

    public function registerEcomkassaPaytiCashboxHandler($eventManager): void
    {
        $eventManager->registerEventHandler('sale', 'OnGetCustomCashboxHandlers', $this->MODULE_ID, '\Ecomkassa\Payti\Handler', 'onGetCustomCashboxHandler');
    }

    public function unregisterHandlers(): void
    {
        $eventManager = EventManager::getInstance();

        $eventManager->unregisterEventHandler('sale', 'OnGetCustomCashboxHandlers', $this->MODULE_ID, '\Ecomkassa\Payti\Handler', 'onGetCustomCashboxHandler');
    }
}
