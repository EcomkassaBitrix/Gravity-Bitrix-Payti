<?php

namespace Ecomkassa\Payti;

use Bitrix\Main\PhoneNumber;
use Bitrix\Main;
use Bitrix\Main\Localization;
use Bitrix\Sale\Cashbox\Internals\CashboxTable;
use Bitrix\Sale\Result;
use Bitrix\Catalog;
use Bitrix\Sale\Cashbox\Cashbox;
use Bitrix\Sale\Cashbox\Check;
use Bitrix\Sale\Cashbox\CorrectionCheck;
use Bitrix\Sale\Cashbox\IPrintImmediately;
use Bitrix\Sale\Cashbox\ICorrection;
use Bitrix\Sale\Cashbox\ICheckable;
use Bitrix\Sale\Cashbox\MeasureCodeToTag2108Mapper;
use Bitrix\Sale\Cashbox\Logger;
use Bitrix\Sale\Cashbox\CheckManager;
use Bitrix\Sale\Cashbox\Errors;
use Bitrix\Sale\Cashbox\SellCheck;
use Bitrix\Sale\Cashbox\SellReturnCashCheck;
use Bitrix\Sale\Cashbox\SellReturnCheck;
use Bitrix\Sale\Cashbox\AdvancePaymentCheck;
use Bitrix\Sale\Cashbox\AdvanceReturnCashCheck;
use Bitrix\Sale\Cashbox\AdvanceReturnCheck;
use Bitrix\Sale\Cashbox\PrepaymentCheck;
use Bitrix\Sale\Cashbox\PrepaymentReturnCheck;
use Bitrix\Sale\Cashbox\PrepaymentReturnCashCheck;
use Bitrix\Sale\Cashbox\FullPrepaymentCheck;
use Bitrix\Sale\Cashbox\FullPrepaymentReturnCheck;
use Bitrix\Sale\Cashbox\FullPrepaymentReturnCashCheck;
use Bitrix\Sale\Cashbox\CreditCheck;
use Bitrix\Sale\Cashbox\CreditReturnCheck;
use Bitrix\Sale\Cashbox\CreditPaymentCheck;
use Bitrix\Sale\Cashbox\CreditPaymentReturnCashCheck;
use Bitrix\Sale\Cashbox\CreditPaymentReturnCheck;

Localization\Loc::loadMessages(__FILE__);

/**
 * Class Payti
 * @package Bitrix\Sale\Cashbox
 */
class PaytiCheck extends Cashbox implements IPrintImmediately, ICheckable, ICorrection
{
    public const OPERATION_CHECK_REGISTRY = 'registry';
    public const OPERATION_CHECK_CHECK = 'check';
    public const OPERATION_GET_TOKEN = 'get_token';

    public const REQUEST_TYPE_GET = 'get';
    public const REQUEST_TYPE_POST = 'post';

    public const TOKEN_OPTION_NAME = 'ecomkassa_payti_access_token';

    public const SERVICE_URL = 'https://app.ecomkassa.ru/fiscalorder/v5';
    public const SERVICE_TEST_URL = 'https://app.ecomkassa.ru/fiscalorder/v5';

    public const RESPONSE_HTTP_CODE_401 = 401;
    public const RESPONSE_HTTP_CODE_200 = 200;

    public const CODE_VAT_NONE = 'none';
    public const CODE_VAT_0 = 'vat0';
    public const CODE_VAT_5 = 'vat5';
    public const CODE_VAT_7 = 'vat7';
    public const CODE_VAT_10 = 'vat10';
    public const CODE_VAT_20 = 'vat20';

    public const CODE_CALC_VAT_10 = 'vat10';
    public const CODE_CALC_VAT_20 = 'vat20';
    public const CODE_CALC_VAT_105 = 'vat105';
    public const CODE_CALC_VAT_107 = 'vat107';
    public const CODE_CALC_VAT_110 = 'vat110';
    public const CODE_CALC_VAT_120 = 'vat120';
    public const HANDLER_MODE_ACTIVE = 'ACTIVE';
    public const HANDLER_MODE_TEST = 'TEST';

    protected const MAX_NAME_LENGTH = 128;

    private const MARK_CODE_TYPE_GS1_M = 'gs1m';

    /**
     * @param Check $check
     * @return array
     */
    public function buildCheckQuery(Check $check)
    {
        $data = $check->getDataForCheck();

        Logger::addDebugInfo(__FUNCTION__ . ':' . json_encode($data));

        /** @var Main\Type\DateTime $dateTime */
        $dateTime = $data['date_create'];

        $serviceEmail = $this->getValueFromSettings('SERVICE', 'EMAIL');
        if (!$serviceEmail) {
            $serviceEmail = static::getDefaultServiceEmail();
        }

        $result = [
            'timestamp' => $dateTime->format('d.m.Y H:i:s'),
            'external_id' => static::buildUuid(static::UUID_TYPE_CHECK, $data['unique_id']),
            'service' => [
                'callback_url' => $this->getCallbackUrl(),
            ],
            'receipt' => [
                'client' => [],
                'company' => [
                    'email' => $serviceEmail,
                    'sno' => $this->getValueFromSettings('TAX', 'SNO'),
                    'inn' => $this->getValueFromSettings('SERVICE', 'INN'),
                    'payment_address' => $this->getValueFromSettings('SERVICE', 'P_ADDRESS'),
                ],
                'payments' => [],
                'items' => [],
                'total' => (float)$data['total_sum']
            ]
        ];

        $email = $data['client_email'] ?? '';

        $phone = \NormalizePhone($data['client_phone']);
        if (is_string($phone)) {
            if ($phone[0] !== '7') {
                $phone = '7'.$phone;
            }

            $phone = '+'.$phone;
        } else {
            $phone = '';
        }

        $clientInfo = $this->getValueFromSettings('CLIENT', 'INFO');
        if ($clientInfo === 'PHONE') {
            $result['receipt']['client'] = ['phone' => $phone];
        } elseif ($clientInfo === 'EMAIL') {
            $result['receipt']['client'] = ['email' => $email];
        } else {
            $result['receipt']['client'] = [];

            if ($email) {
                $result['receipt']['client']['email'] = $email;
            }

            if ($phone) {
                $result['receipt']['client']['phone'] = $phone;
            }
        }

        if (isset($data['payments'])) {
            $paymentTypeMap = $this->getPaymentTypeMap();
            foreach ($data['payments'] as $payment) {
                $result['receipt']['payments'][] = [
                    'type' => $paymentTypeMap[$payment['type']],
                    'sum' => (float)$payment['sum']
                ];
            }
        }

        foreach ($data['items'] as $item) {
            $result['receipt']['items'][] = $this->buildPosition($data, $item);
        }

        return $result;
    }

    /**
     * @return string
     */
    protected function getCallbackUrl()
    {
        $context = Main\Application::getInstance()->getContext();
        $scheme = $context->getRequest()->isHttps() ? 'https' : 'http';
        $server = $context->getServer();
        $domain = $server->getServerName();

        if (preg_match('/^(?<domain>.+):(?<port>\d+)$/', $domain, $matches)) {
            $domain = $matches['domain'];
            $port   = $matches['port'];
        } else {
            $port = $server->getServerPort();
        }
        $port = in_array($port, array(80, 443)) ? '' : ':'.$port;

        return sprintf('%s://%s%s/bitrix/tools/sale_farm_check_print.php', $scheme, $domain, $port);
    }

    /**
     * @param array $data
     * @return array
     */
    protected static function extractCheckData(array $data)
    {
        $result = array();

        if (!$data['uuid']) {
            return $result;
        }

        $checkInfo = CheckManager::getCheckInfoByExternalUuid($data['uuid']);
        if (empty($checkInfo)) {
            return $result;
        }

        if ($data['error']) {
            $errorType = static::getErrorType($data['error']['code']);

            $result['ERROR'] = array(
                'CODE' => $data['error']['code'],
                'MESSAGE' => $data['error']['text'],
                'TYPE' => ($errorType === Errors\Error::TYPE) ? Errors\Error::TYPE : Errors\Warning::TYPE
            );
        }

        $result['ID'] = $checkInfo['ID'];
        $result['CHECK_TYPE'] = $checkInfo['TYPE'];

        $check = CheckManager::getObjectById($checkInfo['ID']);
        $dateTime = new Main\Type\DateTime($data['payload']['receipt_datetime'], 'd.m.Y H:i:s');
        $result['LINK_PARAMS'] = array(
            Check::PARAM_REG_NUMBER_KKT => $data['payload']['ecr_registration_number'],
            Check::PARAM_FISCAL_DOC_ATTR => $data['payload']['fiscal_document_attribute'],
            Check::PARAM_FISCAL_DOC_NUMBER => $data['payload']['fiscal_document_number'],
            Check::PARAM_FISCAL_RECEIPT_NUMBER => $data['payload']['fiscal_receipt_number'],
            Check::PARAM_FN_NUMBER => $data['payload']['fn_number'],
            Check::PARAM_SHIFT_NUMBER => $data['payload']['shift_number'],
            Check::PARAM_DOC_SUM => $data['payload']['total'],
            Check::PARAM_DOC_TIME => $dateTime->getTimestamp(),
            Check::PARAM_CALCULATION_ATTR => $check::getCalculatedSign()
        );

        return $result;
    }

    /**
     * @param $id
     * @return array
     */
    public function buildZReportQuery($id)
    {
        return array();
    }

    /**
     * @param array $data
     * @return array
     */
    protected static function extractZReportData(array $data)
    {
        return array();
    }

    /**
     * @param Check $check
     * @return Result
     * @throws Main\SystemException
     */
    public function printImmediately(Check $check)
    {
        $checkQuery = $this->buildCheckQuery($check);
        $validateResult = $this->validateCheckQuery($checkQuery);

        if (!$validateResult->isSuccess()) {
            return $validateResult;
        }

        $operation = 'sell';

        if ($check::getCalculatedSign() === Check::CALCULATED_SIGN_CONSUMPTION) {
            $operation = 'sell_refund';
        }

        return $this->registerCheck($operation, $checkQuery);
    }

    /**
     * @param $operation
     * @param array $check
     * @return Result
     * @throws Main\SystemException
     */
    protected function registerCheck($operation, array $check)
    {
        $printResult = new Result();

        $token = $this->getAccessToken();
        if ($token === '') {
            $token = $this->requestAccessToken();
            if ($token === '') {
                $printResult->addError(new Main\Error(Localization\Loc::getMessage('SALE_CASHBOX_ECOMKASSA_PAYTI_REQUEST_TOKEN_ERROR')));
                return $printResult;
            }
        }

        $url = $this->getRequestUrl(static::OPERATION_CHECK_REGISTRY, $token, ['CHECK_TYPE' => $operation]);
        $result = $this->send(static::REQUEST_TYPE_POST, $url, $check);

        if (!$result->isSuccess()) {
            return $result;
        }

        $response = $result->getData();

        if ($response['http_code'] === static::RESPONSE_HTTP_CODE_401) {
            $token = $this->requestAccessToken();
            if ($token === '') {
                $printResult->addError(new Main\Error(Localization\Loc::getMessage('SALE_CASHBOX_ECOMKASSA_PAYTI_REQUEST_TOKEN_ERROR')));
                return $printResult;
            }

            $url = $this->getRequestUrl(static::OPERATION_CHECK_REGISTRY, $token, array('CHECK_TYPE' => $operation));
            $result = $this->send(static::REQUEST_TYPE_POST, $url, $check);
            if (!$result->isSuccess()) {
                return $result;
            }

            $response = $result->getData();
        }

        if ($response['http_code'] === static::RESPONSE_HTTP_CODE_200) {
            if ($response['uuid']) {
                $printResult->setData(array('UUID' => $response['uuid']));
            } else {
                $printResult->addError(new Main\Error(Localization\Loc::getMessage('SALE_CASHBOX_ECOMKASSA_PAYTI_CHECK_REG_ERROR')));
            }
        } else {
            if (isset($response['error']['text'])) {
                $printResult->addError(new Main\Error($response['error']['text']));
            } else {
                $printResult->addError(new Main\Error(Localization\Loc::getMessage('SALE_CASHBOX_ECOMKASSA_PAYTI_CHECK_REG_ERROR')));
            }
        }

        return $printResult;
    }

    /**
     * @param Check $check
     * @return Result
     */
    public function check(Check $check)
    {
        return $this->checkByUuid(
            $check->getField('EXTERNAL_UUID')
        );
    }

    protected function checkByUuid($uuid)
    {
        $url = $this->getRequestUrl(
            static::OPERATION_CHECK_CHECK,
            $this->getAccessToken(),
            ['EXTERNAL_UUID' => $uuid]
        );

        $result = $this->send(static::REQUEST_TYPE_GET, $url);
        if (!$result->isSuccess()) {
            return $result;
        }

        $response = $result->getData();
        if ($response['http_code'] === static::RESPONSE_HTTP_CODE_401) {
            $token = $this->requestAccessToken();
            if ($token === '') {
                $result->addError(new Main\Error(Localization\Loc::getMessage('SALE_CASHBOX_ECOMKASSA_PAYTI_REQUEST_TOKEN_ERROR')));
                return $result;
            }

            $url = $this->getRequestUrl(
                static::OPERATION_CHECK_CHECK,
                $this->getAccessToken(),
                ['EXTERNAL_UUID' => $uuid]
            );

            $result = $this->send(static::REQUEST_TYPE_GET, $url);
            if (!$result->isSuccess()) {
                return $result;
            }

            $response = $result->getData();
        }

        $response['uuid'] = $uuid;

        if ($response['status'] === 'wait') {
            $result->addError(new Main\Error(Localization\Loc::getMessage('SALE_CASHBOX_ECOMKASSA_PAYTI_REQUEST_STATUS_WAIT')));
            return $result;
        }

        return static::applyCheckResult($response);
    }

    /**
     * @param $method
     * @param $url
     * @param array $data
     * @return Result
     */
    private function send($method, $url, array $data = array())
    {
        $result = new Result();

        $http = new Main\Web\HttpClient();
        $http->setHeader('Content-Type', 'application/json; charset=utf-8');

        if ($method === static::REQUEST_TYPE_POST) {
            $http->disableSslVerification();
            $data = $this->encode($data);

            $response = $http->post($url, $data);
        } else {
            $response = $http->get($url);
        }

        Logger::addDebugInfo(
            $this->encode([
                'request' => [
                    'method' => strtoupper($method),
                    'url' => $url,
                    'data' => $data,
                ],
                'response' => [
                    'data' => $this->decode($response),
                    'htto_code' => $http->getStatus(),
                ]
            ])
        );

        if ($response !== false) {

            try {
                $response = $this->decode($response);
                if (!is_array($response)) {
                    $response = [];
                }

                $response['http_code'] = $http->getStatus();
                $result->addData($response);
            } catch (Main\ArgumentException $e) {
                $result->addError(new Main\Error($e->getMessage()));
            }
        } else {
            $error = $http->getError();
            foreach ($error as $code => $message) {
                $result->addError(new Main\Error($message, $code));
            }
        }

        return $result;
    }

    /**
     * @param int $modelId
     * @return array
     */
    public static function getSettings($modelId = 0)
    {
        $settings = [];
        $settings['MSG'] = [
            'LABEL' => '</td>
</tr><tr class="ecomkassa-payti-warning-trick">
<td colspan="2" style="text-align: center;">
<div class="adm-info-message-wrap"><div class="adm-info-message" style="width: 100%; box-sizing: border-box;">
            <div>' . Localization\Loc::getMessage('SALE_CASHBOX_ECOMKASSA_PAYTI_WARNING') . '</div></div></div><script>
document.querySelector(".ecomkassa-payti-warning-trick").previousSibling.style.display = "none";

if (tr_NUMBER_KKM) {
    var d = document.createElement("div");
    d.innerHTML = "<span style=\'font-size: 85%;\'>Совпадает с идентификатором из личного кабинета.</span>";
    tr_NUMBER_KKM.children[0].append(d)
}

if (tr_USE_OFFLINE) {
    tr_USE_OFFLINE.style.display = \'none\';
}

</script>'
        ];

        $settings['AUTH'] = [
                'LABEL' => Localization\Loc::getMessage('SALE_CASHBOX_ECOMKASSA_PAYTI_FARM_SETTINGS_AUTH'),
                'REQUIRED' => 'Y',
                'ITEMS' => array(
                    'LOGIN' => array(
                        'TYPE' => 'STRING',
                        'LABEL' => Localization\Loc::getMessage('SALE_CASHBOX_ECOMKASSA_PAYTI_FARM_SETTINGS_AUTH_LOGIN_LABEL')
                    ),
                    'PASS' => array(
                        'TYPE' => 'STRING',
                        'LABEL' => Localization\Loc::getMessage('SALE_CASHBOX_ECOMKASSA_PAYTI_FARM_SETTINGS_AUTH_PASS_LABEL')
                    ),
                )
            ];

        $settings['SERVICE'] = [
                'LABEL' => Localization\Loc::getMessage('SALE_CASHBOX_ECOMKASSA_PAYTI_FARM_SETTINGS_SERVICE'),
                'REQUIRED' => 'Y',
                'ITEMS' => array(
                    'INN' => array(
                        'TYPE' => 'STRING',
                        'LABEL' => Localization\Loc::getMessage('SALE_CASHBOX_ECOMKASSA_PAYTI_FARM_SETTINGS_SERVICE_INN_LABEL')
                    ),
                    'P_ADDRESS' => array(
                        'TYPE' => 'STRING',
                        'LABEL' => Localization\Loc::getMessage('SALE_CASHBOX_ECOMKASSA_PAYTI_FARM_SETTINGS_SERVICE_URL_LABEL')
                    ),
                )
            ];
        $settings['CLIENT'] = [
                'LABEL' => Localization\Loc::getMessage('SALE_CASHBOX_ECOMKASSA_PAYTI_FARM_SETTINGS_CLIENT'),
                'ITEMS' => array(
                    'INFO' => array(
                        'TYPE' => 'ENUM',
                        'VALUE' => 'NONE',
                        'LABEL' => Localization\Loc::getMessage('SALE_CASHBOX_ECOMKASSA_PAYTI_FARM_SETTINGS_CLIENT_INFO'),
                        'OPTIONS' => array(
                            'NONE' => Localization\Loc::getMessage('SALE_CASHBOX_ECOMKASSA_PAYTI_FARM_SETTINGS_CLIENT_NONE'),
                            'PHONE' => Localization\Loc::getMessage('SALE_CASHBOX_ECOMKASSA_PAYTI_FARM_SETTINGS_CLIENT_PHONE'),
                            'EMAIL' => Localization\Loc::getMessage('SALE_CASHBOX_ECOMKASSA_PAYTI_FARM_SETTINGS_CLIENT_EMAIL'),
                        )
                    ),
                )
            ];

        $settings['PAYMENT_TYPE'] = array(
            'LABEL' => Localization\Loc::getMessage('SALE_CASHBOX_ECOMKASSA_PAYTI_FARM_SETTINGS_P_TYPE'),
            'REQUIRED' => 'Y',
            'ITEMS' => array()
        );

        $systemPaymentType = array(
            Check::PAYMENT_TYPE_CASH => 0,
            Check::PAYMENT_TYPE_CASHLESS => 1,
        );
        foreach ($systemPaymentType as $type => $value) {
            $settings['PAYMENT_TYPE']['ITEMS'][$type] = array(
                'TYPE' => 'STRING',
                'LABEL' => Localization\Loc::getMessage('SALE_CASHBOX_ECOMKASSA_PAYTI_FARM_SETTINGS_P_TYPE_LABEL_'.mb_strtoupper($type)),
                'VALUE' => $value
            );
        }

        $settings['VAT'] = array(
            'LABEL' => Localization\Loc::getMessage('SALE_CASHBOX_BITRIX_SETTINGS_VAT'),
            'REQUIRED' => 'Y',
            'ITEMS' => array(
                'NOT_VAT' => array(
                    'TYPE' => 'STRING',
                    'LABEL' => Localization\Loc::getMessage('SALE_CASHBOX_BITRIX_SETTINGS_VAT_LABEL_NOT_VAT'),
                    'VALUE' => 'none'
                )
            )
        );

        if (Main\Loader::includeModule('catalog')) {
            $dbRes = Catalog\VatTable::getList(['filter' => ['ACTIVE' => 'Y']]);
            $vatList = $dbRes->fetchAll();
            if ($vatList) {
                $defaultVatList = [
                    0 => self::CODE_VAT_0,
                    10 => self::CODE_VAT_10,
                    20 => self::CODE_VAT_20
                ];

                foreach ($vatList as $vat) {
                    $value = '';
                    if (isset($defaultVatList[(int)$vat['RATE']])) {
                        $value = $defaultVatList[(int)$vat['RATE']];
                    }

                    $settings['VAT']['ITEMS'][(int)$vat['ID']] = array(
                        'TYPE' => 'STRING',
                        'LABEL' => $vat['NAME'].' ['.(int)$vat['RATE'].'%]',
                        'VALUE' => $value
                    );
                }
            }
        }

        $settings['TAX'] = array(
            'LABEL' => Localization\Loc::getMessage('SALE_CASHBOX_ECOMKASSA_PAYTI_FARM_SETTINGS_SNO'),
            'REQUIRED' => 'Y',
            'ITEMS' => array(
                'SNO' => array(
                    'TYPE' => 'ENUM',
                    'LABEL' => Localization\Loc::getMessage('SALE_CASHBOX_ECOMKASSA_PAYTI_FARM_SETTINGS_SNO_LABEL'),
                    'VALUE' => 'osn',
                    'OPTIONS' => array(
                        'osn' => Localization\Loc::getMessage('SALE_CASHBOX_ECOMKASSA_PAYTI_FARM_SNO_OSN'),
                        'usn_income' => Localization\Loc::getMessage('SALE_CASHBOX_ECOMKASSA_PAYTI_FARM_SNO_UI'),
                        'usn_income_outcome' => Localization\Loc::getMessage('SALE_CASHBOX_ECOMKASSA_PAYTI_FARM_SNO_UIO'),
                        'envd' => Localization\Loc::getMessage('SALE_CASHBOX_ECOMKASSA_PAYTI_FARM_SNO_ENVD'),
                        'esn' => Localization\Loc::getMessage('SALE_CASHBOX_ECOMKASSA_PAYTI_FARM_SNO_ESN'),
                        'patent' => Localization\Loc::getMessage('SALE_CASHBOX_ECOMKASSA_PAYTI_FARM_SNO_PATENT')
                    )
                )
            )
        );

        if (static::hasMeasureSettings()) {
            $settings['MEASURE'] = static::getMeasureSettings();
        }

        unset($settings['PAYMENT_TYPE']);

        $settings['SERVICE']['ITEMS']['EMAIL'] = [
            'TYPE' => 'STRING',
            'LABEL' => Localization\Loc::getMessage('SALE_CASHBOX_ECOMKASSA_PAYTI_FARM_SETTINGS_SERVICE_EMAIL_LABEL'),
            'VALUE' => static::getDefaultServiceEmail()
        ];

        $settings['INTERACTION'] = [
            'LABEL' => Localization\Loc::getMessage('SALE_CASHBOX_ECOMKASSA_PAYTI_FARM_SETTINGS_INTERACTION'),
            'ITEMS' => [
                'MODE_HANDLER' => [
                    'TYPE' => 'ENUM',
                    'LABEL' => Localization\Loc::getMessage('SALE_CASHBOX_ECOMKASSA_PAYTI_FARM_SETTINGS_MODE_HANDLER_LABEL'),
                    'OPTIONS' => [
                        static::HANDLER_MODE_ACTIVE => Localization\Loc::getMessage('SALE_CASHBOX_ECOMKASSA_PAYTI_FARM_MODE_ACTIVE'),
                        static::HANDLER_MODE_TEST => Localization\Loc::getMessage('SALE_CASHBOX_ECOMKASSA_PAYTI_FARM_MODE_TEST'),
                    ]
                ]
            ]
        ];

        return $settings;
    }

    /**
     * @return bool
     */
    protected static function hasMeasureSettings(): bool
    {
        return true;
    }

    /**
     * @return array
     */
    protected static function getMeasureSettings(): array
    {
        $measureItems = [
            'DEFAULT' => [
                'TYPE' => 'STRING',
                'LABEL' => Localization\Loc::getMessage('SALE_CASHBOX_MEASURE_SUPPORT_SETTINGS_DEFAULT_VALUE'),
                'VALUE' => 0,
            ]
        ];
        if (Main\Loader::includeModule('catalog')) {
            $measuresList = \CCatalogMeasure::getList();
            while ($measure = $measuresList->fetch()) {
                $measureItems[$measure['CODE']] = [
                    'TYPE' => 'STRING',
                    'LABEL' => $measure['MEASURE_TITLE'],
                    'VALUE' => MeasureCodeToTag2108Mapper::getTag2108Value($measure['CODE']),
                ];
            }
        }

        return [
            'LABEL' => Localization\Loc::getMessage('SALE_CASHBOX_MEASURE_SUPPORT_SETTINGS'),
            'ITEMS' => $measureItems,
        ];
    }

    /**
     * @return array
     */
    public static function getGeneralRequiredFields()
    {
        $generalRequiredFields = parent::getGeneralRequiredFields();

        $map = CashboxTable::getMap();
        $generalRequiredFields['NUMBER_KKM'] = $map['NUMBER_KKM']['title'];
        return $generalRequiredFields;
    }

    /**
     * @return string
     */
    private function getAccessToken()
    {
        return Main\Config\Option::get('sale', $this->getOptionName(), '');
    }

    /**
     * @param $token
     */
    private function setAccessToken($token)
    {
        Main\Config\Option::set('sale', $this->getOptionName(), $token);
    }

    /**
     * @return string
     */
    private function getOptionName()
    {
        return static::getOptionPrefix() . '_' .mb_strtolower($this->getField('NUMBER_KKM'));
    }

    /**
     * @return string
     */
    protected function getOptionPrefix(): string
    {
        return static::TOKEN_OPTION_NAME;
    }

    /**
     * @param array $data
     * @return mixed
     */
    private function encode(array $data)
    {
        return Main\Web\Json::encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param $data
     * @return mixed
     * @throws Main\ArgumentException
     */
    private function decode($data)
    {
        return Main\Web\Json::decode($data);
    }

    /**
     * @return string
     * @throws Main\SystemException
     */
    private function requestAccessToken()
    {
        $url = $this->getRequestUrl(static::OPERATION_GET_TOKEN, '');

        $data = array(
            'login' => $this->getValueFromSettings('AUTH', 'LOGIN'),
            'pass' => $this->getValueFromSettings('AUTH', 'PASS')
        );

        $result = $this->send(static::REQUEST_TYPE_POST, $url, $data);

        if ($result->isSuccess()) {
            $response = $result->getData();

            if (isset($response['token'])) {
                $this->setAccessToken($response['token']);

                return $response['token'];
            }
        }

        return '';
    }

    /**
     * @param $errorCode
     * @throws Main\NotImplementedException
     * @return int
     */
    protected static function getErrorType($errorCode)
    {
        return Errors\Error::TYPE;
    }

    /**
     * @param array $item
     * @return int|null
     */
    private function buildPositionMeasure(array $item): ?int
    {
        $tag2108Value = $this->getValueFromSettings('MEASURE', $item['measure_code']);
        if (is_null($tag2108Value) || $tag2108Value === '') {
            $tag2108Value = $this->getValueFromSettings('MEASURE', 'DEFAULT');
        }

        return (is_null($tag2108Value) || $tag2108Value === '') ? null : (int)$tag2108Value;
    }

    /**
     * @param array $item
     * @return array
     */
    private function buildPositionGs1mMarkCode(array $item): array
    {
        return [
            self::MARK_CODE_TYPE_GS1_M => base64_encode($item['marking_code']),
        ];
    }

    private function buildPositionAgentInfo(): array
    {
        /**
         * tag 1222
         */
        return [
            'type' => 'another',
        ];
    }

    private function buildPositionSupplierInfo(array $supplier): array
    {
        $supplierInfo = [];

        if (!empty($supplier['phones'])) {
            $phoneParser = PhoneNumber\Parser::getInstance();

            foreach ($supplier['phones'] as $phone) {
                $phoneNumber = $phoneParser->parse($phone);
                $formattedPhone = $phoneNumber->format(PhoneNumber\Format::E164);
                if ($formattedPhone) {
                    $supplierInfo['phones'][] = $formattedPhone;
                }
            }
        }

        if (!empty($supplier['name'])) {
            $supplierInfo['name'] = mb_substr($supplier['name'], 0, 256);
        }

        if (empty($supplier['inn'])) {
            $supplierInfo['inn'] = '000000000000';
        } else {
            $supplierInfo['inn'] = $supplier['inn'];
        }

        return $supplierInfo;
    }

    /**
     * @inheritDoc
     */
    public static function isCorrectionOn(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public static function getFfdVersion(): ?float
    {
        return 1.2;
    }

    /**
     * @param array $item
     * @return string
     */
    protected function buildPositionName(array $item)
    {
        return mb_substr($item['name'], 0, static::MAX_NAME_LENGTH);
    }

    /**
     * @param array $item
     * @return mixed|string
     */
    protected function buildPositionPaymentObject(array $item)
    {
        $paymentObjectMap = $this->getPaymentObjectMap();

        return $paymentObjectMap[$item['payment_object']];
    }

    /**
     * @param array $item
     * @return float
     */
    protected function buildPositionPrice(array $item)
    {
        return (float)$item['price'];
    }

    /**
     * @return array
     */
    private function getPaymentTypeMap()
    {
        return array(
            Check::PAYMENT_TYPE_CASH => 0,
            Check::PAYMENT_TYPE_CASHLESS => 1,
            Check::PAYMENT_TYPE_ADVANCE => 2,
            Check::PAYMENT_TYPE_CREDIT => 3,
        );
    }

    /**
     * @inheritDoc
     */
    protected function getPaymentObjectMap()
    {
        return [
            Check::PAYMENT_OBJECT_COMMODITY => 1,
            Check::PAYMENT_OBJECT_SERVICE => 4,
            Check::PAYMENT_OBJECT_JOB => 3,
            Check::PAYMENT_OBJECT_EXCISE => 2,
            Check::PAYMENT_OBJECT_PAYMENT => 10,
            Check::PAYMENT_OBJECT_GAMBLING_BET => 5,
            Check::PAYMENT_OBJECT_GAMBLING_PRIZE => 6,
            Check::PAYMENT_OBJECT_LOTTERY => 7,
            Check::PAYMENT_OBJECT_LOTTERY_PRIZE => 8,
            Check::PAYMENT_OBJECT_INTELLECTUAL_ACTIVITY => 9,
            Check::PAYMENT_OBJECT_AGENT_COMMISSION => 11,
            Check::PAYMENT_OBJECT_COMPOSITE => 12,
            Check::PAYMENT_OBJECT_ANOTHER => 13,
            Check::PAYMENT_OBJECT_PROPERTY_RIGHT => 14,
            Check::PAYMENT_OBJECT_NON_OPERATING_GAIN => 15,
            Check::PAYMENT_OBJECT_SALES_TAX => 17,
            Check::PAYMENT_OBJECT_RESORT_FEE => 18,
            Check::PAYMENT_OBJECT_DEPOSIT => 19,
            Check::PAYMENT_OBJECT_EXPENSE => 20,
            Check::PAYMENT_OBJECT_PENSION_INSURANCE_IP => 21,
            Check::PAYMENT_OBJECT_PENSION_INSURANCE => 22,
            Check::PAYMENT_OBJECT_MEDICAL_INSURANCE_IP => 23,
            Check::PAYMENT_OBJECT_MEDICAL_INSURANCE => 24,
            Check::PAYMENT_OBJECT_SOCIAL_INSURANCE => 25,
            Check::PAYMENT_OBJECT_CASINO_PAYMENT => 26,
            Check::PAYMENT_OBJECT_COMMODITY_MARKING_NO_MARKING_EXCISE => 30,
            Check::PAYMENT_OBJECT_COMMODITY_MARKING_EXCISE => 31,
            Check::PAYMENT_OBJECT_COMMODITY_MARKING_NO_MARKING => 32,
            Check::PAYMENT_OBJECT_COMMODITY_MARKING => 33,
        ];
    }

    /**
     * @param array $item
     * @return float
     */
    protected function buildPositionSum(array $item)
    {
        return (float)$item['sum'];
    }

    /**
     * @param array $checkData
     * @return Result
     */
    protected function validateCheckQuery(array $checkData)
    {
        $result = new Result();

        if (empty($checkData['receipt']['client']['email']) && empty($checkData['receipt']['client']['phone'])) {
            $result->addError(new Main\Error(Localization\Loc::getMessage('SALE_CASHBOX_ECOMKASSA_PAYTI_ERR_EMPTY_PHONE_EMAIL')));
        }

        foreach ($checkData['receipt']['items'] as $item) {
            if ($item['vat'] === null) {
                $result->addError(new Main\Error(Localization\Loc::getMessage('SALE_CASHBOX_ECOMKASSA_PAYTI_ERR_EMPTY_TAX')));
                break;
            }
        }

        return $result;
    }

    /**
     * @param $operation
     * @param $token
     * @param array $queryData
     * @return string
     * @throws Main\SystemException
     */
    protected function getRequestUrl($operation, $token, array $queryData = array())
    {
        $serviceUrl = static::SERVICE_URL;

        if ($this->getValueFromSettings('INTERACTION', 'MODE_HANDLER') === static::HANDLER_MODE_TEST) {
            $serviceUrl = static::SERVICE_TEST_URL;
        }

        $groupCode = $this->getField('NUMBER_KKM');

        if ($operation === static::OPERATION_CHECK_REGISTRY) {
            return $serviceUrl.'/'.$groupCode.'/'.$queryData['CHECK_TYPE'].'?token='.$token;
        } elseif ($operation === static::OPERATION_CHECK_CHECK) {
            return $serviceUrl.'/'.$groupCode.'/report/'.$queryData['EXTERNAL_UUID'].'?token='.$token;
        } elseif ($operation === static::OPERATION_GET_TOKEN) {
            return $serviceUrl.'/getToken';
        }

        throw new Main\SystemException();
    }

    /**
     * @inheritDoc
     */
    protected function buildPosition(array $checkData, array $item): array
    {
        $result = [
            'name' => $this->buildPositionName($item),
            'price' => $this->buildPositionPrice($item),
            'sum' => $this->buildPositionSum($item),
            'quantity' => $this->buildPositionQuantity($item),
            'measure' => $this->buildPositionMeasure($item),
            'payment_method' => $this->buildPositionPaymentMethod($checkData),
            'payment_object' => $this->buildPositionPaymentObject($item),
            'vat' => [
                'type' => $this->buildPositionVatType($checkData, $item)
            ],
        ];

        if (isset($item['marking_code'])) {
            $result['mark_processing_mode'] = '0';
            $result['mark_code'] = $this->buildPositionGs1mMarkCode($item);
        }

        if (isset($item['supplier_info'])) {
            $result['agent_info'] = $this->buildPositionAgentInfo();
            $result['supplier_info'] = $this->buildPositionSupplierInfo($item['supplier_info']);
        }

        return $result;
    }

    /**
     * @param array $item
     * @return mixed
     */
    protected function buildPositionQuantity(array $item)
    {
        return $item['quantity'];
    }


    /**
     * @param array $checkData
     * @return mixed|string
     */
    protected function buildPositionPaymentMethod(array $checkData)
    {
        $checkTypeMap = $this->getCheckTypeMap();

        return $checkTypeMap[$checkData['type']];
    }

    /**
     * @param array $checkData
     * @param array $item
     * @return mixed|string
     */
    protected function buildPositionVatType(array $checkData, array $item)
    {
        $vat = $this->getValueFromSettings('VAT', $item['vat']);
        if ($vat === null) {
            $vat = $this->getValueFromSettings('VAT', 'NOT_VAT');
        }

        return $this->mapVatValue($checkData['type'], $vat);
    }

    /**
     * @param $code
     * @return string
     */
    protected function buildPositionNomenclatureCode($code)
    {
        $hexCode = bin2hex($code);
        $hexCodeArray = str_split($hexCode, 2);
        $hexCodeArray = array_map('ToUpper', $hexCodeArray);

        return join(' ', $hexCodeArray);
    }

    /**
     * @param CorrectionCheck $check
     * @return Result
     * @throws Main\SystemException
     */
    public function printCorrectionImmediately(CorrectionCheck $check)
    {
        $checkQuery = $this->buildCorrectionCheckQuery($check);
        $operation = 'sell_correction';

        if ($check::getCalculatedSign() === Check::CALCULATED_SIGN_CONSUMPTION) {
            $operation = 'sell_refund';
        }

        return $this->registerCheck($operation, $checkQuery);
    }

    /**
     * @param CorrectionCheck $check
     * @return array
     */
    public function buildCorrectionCheckQuery(CorrectionCheck $check)
    {
        $data = $check->getDataForCheck();

        /** @var Main\Type\DateTime $dateTime */
        $dateTime = $data['date_create'];

        $documentDate = $data['correction_info']['document_date'];
        if (!$documentDate instanceof Main\Type\Date) {
            $documentDate = new Main\Type\Date($documentDate);
        }

        $result = [
            'timestamp' => $dateTime->format('d.m.Y H:i:s'),
            'external_id' => static::buildUuid(static::UUID_TYPE_CHECK, $data['unique_id']),
            'service' => [
                'callback_url' => $this->getCallbackUrl(),
            ],
            'correction' => [
                'company' => [
                    'sno' => $this->getValueFromSettings('TAX', 'SNO'),
                    'inn' => $this->getValueFromSettings('SERVICE', 'INN'),
                    'payment_address' => $this->getValueFromSettings('SERVICE', 'P_ADDRESS'),
                ],
                'correction_info' => [
                    'type' => $data['correction_info']['type'],
                    'base_date' => $documentDate->format('d.m.Y H:i:s'),
                    'base_number' => $data['correction_info']['document_number'],
                    'base_name' => mb_substr(
                        $data['correction_info']['description'],
                        0,
                        255
                    ),
                ],
                'payments' => [],
                'vats' => []
            ]
        ];

        if (isset($data['payments'])) {
            $paymentTypeMap = $this->getPaymentTypeMap();
            foreach ($data['payments'] as $payment) {
                $result['correction']['payments'][] = [
                    'type' => $paymentTypeMap[$payment['type']],
                    'sum' => (float)$payment['sum']
                ];
            }
        }

        if (isset($data['vats'])) {
            foreach ($data['vats'] as $item) {
                $vat = $this->getValueFromSettings('VAT', $item['type']);
                if (is_null($vat) || $vat === '') {
                    $vat = $this->getValueFromSettings('VAT', 'NOT_VAT');
                }

                $result['correction']['vats'][] = [
                    'type' => $vat,
                    'sum' => (float)$item['sum']
                ];
            }
        }

        return $result;
    }

    public function checkCorrection(CorrectionCheck $check)
    {
        return $this->checkByUuid(
            $check->getField('EXTERNAL_UUID')
        );
    }

    /**
     * @param $checkType
     * @param $vat
     * @return mixed
     */
    private function mapVatValue($checkType, $vat)
    {
        $map = [
            self::CODE_VAT_10 => [
                PrepaymentCheck::getType() => self::CODE_CALC_VAT_10,
                PrepaymentReturnCheck::getType() => self::CODE_CALC_VAT_10,
                PrepaymentReturnCashCheck::getType() => self::CODE_CALC_VAT_10,
                FullPrepaymentCheck::getType() => self::CODE_CALC_VAT_10,
                FullPrepaymentReturnCheck::getType() => self::CODE_CALC_VAT_10,
                FullPrepaymentReturnCashCheck::getType() => self::CODE_CALC_VAT_10
            ],
            self::CODE_VAT_20 => [
                PrepaymentCheck::getType() => self::CODE_CALC_VAT_20,
                PrepaymentReturnCheck::getType() => self::CODE_CALC_VAT_20,
                PrepaymentReturnCashCheck::getType() => self::CODE_CALC_VAT_20,
                FullPrepaymentCheck::getType() => self::CODE_CALC_VAT_20,
                FullPrepaymentReturnCheck::getType() => self::CODE_CALC_VAT_20,
                FullPrepaymentReturnCashCheck::getType() => self::CODE_CALC_VAT_20,
            ],
        ];

        return $map[$vat][$checkType] ?? $vat;
    }

    /**
     * @return string
     */
    private static function getDefaultServiceEmail()
    {
        return Main\Config\Option::get('main', 'email_from');
    }

    /**
     * @inheritDoc
     */
    public static function getName()
    {
        return Localization\Loc::getMessage('SALE_CASHBOX_ECOMKASSA_PAYTI_TITLE');
    }

    /**
     * @return array
     */
    protected function getCheckTypeMap()
    {
        return array(
            SellCheck::getType() => 'full_payment',
            SellReturnCashCheck::getType() => 'full_payment',
            SellReturnCheck::getType() => 'full_payment',
            AdvancePaymentCheck::getType() => 'advance',
            AdvanceReturnCashCheck::getType() => 'advance',
            AdvanceReturnCheck::getType() => 'advance',
            PrepaymentCheck::getType() => 'prepayment',
            PrepaymentReturnCheck::getType() => 'prepayment',
            PrepaymentReturnCashCheck::getType() => 'prepayment',
            FullPrepaymentCheck::getType() => 'full_prepayment',
            FullPrepaymentReturnCheck::getType() => 'full_prepayment',
            FullPrepaymentReturnCashCheck::getType() => 'full_prepayment',
            CreditCheck::getType() => 'credit',
            CreditReturnCheck::getType() => 'credit',
            CreditPaymentCheck::getType() => 'credit_payment',
            CreditPaymentReturnCashCheck::getType() => 'credit_payment',
            CreditPaymentReturnCheck::getType() => 'credit_payment',
        );
    }
}
