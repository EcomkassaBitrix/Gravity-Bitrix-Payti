<?php

namespace Payti\Check;

use Bitrix\Main\Application;
use Bitrix\Main\EventResult;

/**
 * Класс интеграции обработчика чеков
 */
class Handler
{
    /**
     * Указание обработчика чеков (вызывается по событию OnGetCustomCashboxHandler после установки модуля)
     */
    public static function onGetCustomCashboxHandler()
    {
        $directory = static::getDirectory();

        return new EventResult(
            EventResult::SUCCESS,
            [
                '\Payti\Check\PaytiCheck' => $directory . '/PaytiCheck.php',
            ]
        );
    }

    /**
     * Получить директорию до обработчика относительно корня сайта
     *
     * @return string
     */
    public static function getDirectory(): string
    {
        $docRoot = Application::getDocumentRoot();
        $directory = substr(__DIR__, mb_strlen($docRoot));

        return $directory;
    }
}
