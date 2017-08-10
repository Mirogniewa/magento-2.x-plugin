<?php

namespace BlueMedia\BluePayment\Helper;

use BlueMedia\BluePayment\Model\GatewaysFactory;
use BlueMedia\BluePayment\Model\ResourceModel\Gateways\Collection;
use Magento\Framework\App\Config\Initial;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\View\LayoutFactory;
use Magento\Payment\Model\Config;
use Magento\Payment\Model\Method\Factory;
use Magento\Store\Model\App\Emulation;

/**
 * Class Gateways
 * @package BlueMedia\BluePayment\Helper
 */
class Gateways extends \BlueMedia\BluePayment\Helper\Data
{
    const FAILED_CONNECTION_RETRY_COUNT = 5;
    const MESSAGE_ID_STRING_LENGTH      = 32;
    const UPLOAD_PATH                   = '/BlueMedia/';

    /**
     * Gateways model factory
     *
     * @var \BlueMedia\BluePayment\Model\GatewaysFactory
     */
    protected $_gatewaysFactory;

    /**
     * Logger
     *
     * @var \Zend\Log\Logger
     */
    protected $_logger;

    /**
     * Gateways constructor.
     *
     * @param \Magento\Framework\App\Helper\Context        $context
     * @param \Magento\Framework\View\LayoutFactory        $layoutFactory
     * @param \Magento\Payment\Model\Method\Factory        $paymentMethodFactory
     * @param \Magento\Store\Model\App\Emulation           $appEmulation
     * @param \Magento\Payment\Model\Config                $paymentConfig
     * @param \Magento\Framework\App\Config\Initial        $initialConfig
     * @param \BlueMedia\BluePayment\Model\GatewaysFactory $gatewaysFactory
     */
    public function __construct(
        Context $context,
        LayoutFactory $layoutFactory,
        Factory $paymentMethodFactory,
        Emulation $appEmulation,
        Config $paymentConfig,
        Initial $initialConfig,
        GatewaysFactory $gatewaysFactory
    ) {
        parent::__construct($context, $layoutFactory, $paymentMethodFactory, $appEmulation, $paymentConfig, $initialConfig);
        $writer        = new \Zend\Log\Writer\Stream(BP . '/var/log/bluemedia.log');
        $this->_logger = new \Zend\Log\Logger();
        $this->_logger->addWriter($writer);
        $this->_gatewaysFactory = $gatewaysFactory;
    }

    /**
     * @return array
     */
    public function syncGateways()
    {
        $result            = [];
        $hashMethod        = $this->scopeConfig->getValue("payment/bluepayment/hash_algorithm");
        $gatewayListAPIUrl = $this->getGatewayListUrl();

        $serviceId = $this->scopeConfig->getValue("payment/bluepayment/service_id");
        $messageId = $this->randomString(self::MESSAGE_ID_STRING_LENGTH);
        $hashKey   = $this->scopeConfig->getValue("payment/bluepayment/shared_key");

        $tryCount   = 0;
        $loadResult = false;
        while (!$loadResult) {
            $loadResult = $this->loadGatewaysFromAPI($hashMethod, $serviceId, $messageId, $hashKey, $gatewayListAPIUrl);
            if ($loadResult) {
                $result['success'] = $this->saveGateways((array)$loadResult);
                break;
            } else {
                if ($tryCount >= self::FAILED_CONNECTION_RETRY_COUNT) {
                    $result['error'] = 'Exceeded the limit of attempts to sync gateways list!';
                    break;
                }
            }
            $tryCount++;
        }

        return $result;
    }

    /**
     * @return mixed
     */
    private function getGatewayListUrl()
    {
        $mode = $this->scopeConfig->getValue("payment/bluepayment/test_mode");
        if ($mode) {
            return $this->scopeConfig->getValue("payment/bluepayment/test_address_gateways_url");
        }

        return $this->scopeConfig->getValue("payment/bluepayment/prod_address_gateways_url");
    }

    /**
     * @param $length
     *
     * @return string
     */
    private function randomString($length)
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $randstring = '';
        for ($i = 0; $i < $length; $i++) {
            $randstring .= $characters[rand(0, strlen($characters) - 1)];
        }

        return $randstring;
    }

    /**
     * @param $hashMethod
     * @param $serviceId
     * @param $messageId
     * @param $hashKey
     * @param $gatewayListAPIUrl
     *
     * @return bool|\SimpleXMLElement
     */
    private function loadGatewaysFromAPI($hashMethod, $serviceId, $messageId, $hashKey, $gatewayListAPIUrl)
    {
        $hash   = hash($hashMethod, $serviceId . '|' . $messageId . '|' . $hashKey);
        $data   = [
            'ServiceID' => $serviceId,
            'MessageID' => $messageId,
            'Hash' => $hash
        ];
        $fields = (is_array($data)) ? http_build_query($data) : $data;
        try {
            $curl = curl_init($gatewayListAPIUrl);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $fields);
            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
            $curlResponse = curl_exec($curl);
            curl_close($curl);
            if ($curlResponse == 'ERROR') {
                return false;
            } else {
                $response = simplexml_load_string($curlResponse);

                return $response;
            }
        } catch (\Exception $e) {
            $this->_logger->info($e->getMessage());

            return false;
        }
    }

    /**
     * @param $gatewayList
     */
    private function saveGateways($gatewayList)
    {
        $existingGateways          = $this->getSimpleGatewaysList();
        $currentlyActiveGatewayIDs = [];

        if (isset($gatewayList['gateway'])) {
            foreach ($gatewayList['gateway'] as $gatewayXMLObject) {
                $gateway = (array)$gatewayXMLObject;
                if (isset($gateway['gatewayID'])
                    && isset($gateway['gatewayName'])
                    && isset($gateway['gatewayType'])
                    && isset($gateway['bankName'])
                    && isset($gateway['iconURL'])
                    && isset($gateway['statusDate'])
                ) {
                    $currentlyActiveGatewayIDs[] = $gateway['gatewayID'];
                    if (isset($existingGateways[$gateway['gatewayID']])) {
                        $gatewayModel = $this->_gatewaysFactory->create();
                        $gatewayModel->load($existingGateways[$gateway['gatewayID']]['entity_id']);
                    } else {
                        $gatewayModel = $this->_gatewaysFactory->create();
                    }

                    $gatewayModel->setData('gateway_id', $gateway['gatewayID']);
                    $gatewayModel->setData('bank_name', $gateway['bankName']);
                    $gatewayModel->setData('gateway_name', $gateway['gatewayName']);
                    $gatewayModel->setData('gateway_type', $gateway['gatewayType']);
                    $gatewayModel->setData('gateway_logo_url', $gateway['iconURL']);
                    $gatewayModel->setData('status_date', $gateway['statusDate']);
                    try {
                        $gatewayModel->save();
                    } catch (\Exception $e) {
                        $this->_logger->info($e->getMessage());
                    }
                }
            }

            foreach ($existingGateways as $existingGatewayId => $existingGatewayData) {
                if (!in_array($existingGatewayId, $currentlyActiveGatewayIDs)
                    && $existingGatewayData['gateway_status']
                    != 0
                ) {
                    $gatewayModel = $this->_gatewaysFactory->create()->load($existingGatewayData['entity_id']);
                    $gatewayModel->setData('gateway_status', 0);
                    try {
                        $gatewayModel->save();
                    } catch (\Exception $e) {
                        $this->_logger->info($e->getMessage());
                    }
                }
            }
        }
    }

    /**
     * @return array
     */
    public function getSimpleGatewaysList()
    {
        $objectManager          = ObjectManager::getInstance();
        $bluegatewaysCollection = $objectManager->create(Collection::class);
        $bluegatewaysCollection->load();

        $existingGateways = [];
        foreach ($bluegatewaysCollection as $blueGateways) {
            $existingGateways[$blueGateways->getData('gateway_id')] = [
                'entity_id' => $blueGateways->getId(),
                'gateway_status' => $blueGateways->getData('gateway_status'),
                'bank_name' => $blueGateways->getData('bank_name'),
                'gateway_name' => $blueGateways->getData('gateway_name'),
                'gateway_description' => $blueGateways->getData('gateway_description'),
                'gateway_sort_order' => $blueGateways->getData('gateway_sort_order'),
                'gateway_type' => $blueGateways->getData('gateway_type'),
                'gateway_logo_url' => $blueGateways->getData('gateway_logo_url'),
                'use_own_logo' => $blueGateways->getData('use_own_logo'),
                'gateway_logo_path' => $blueGateways->getData('gateway_logo_path'),
                'status_date' => $blueGateways->getData('status_date')
            ];
        }

        return $existingGateways;
    }

    /**
     * @return bool
     */
    public function showGatewayLogo()
    {
        if ($this->scopeConfig->getValue("payment/bluepayment/show_gateway_logo") == 1) {
            return true;
        }

        return false;
    }
}