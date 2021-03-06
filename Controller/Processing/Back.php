<?php

namespace BlueMedia\BluePayment\Controller\Processing;

use BlueMedia\BluePayment\Helper\Data;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Sales\Model\OrderFactory;
use BlueMedia\BluePayment\Logger\Logger;

/**
 * Class Back
 *
 * @package BlueMedia\BluePayment\Controller\Processing
 */
class Back extends Action
{
    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var \BlueMedia\BluePayment\Helper\Data
     */
    protected $helper;

    /**
     *
     * @var\Magento\Sales\Model\OrderFactory
     */
    protected $orderFactory;

    /**
     * Back constructor.
     *
     * @param \Magento\Framework\App\Action\Context              $context
     * @param Logger|\Psr\Log\LoggerInterface                    $logger
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \BlueMedia\BluePayment\Helper\Data                 $helper
     * @param \Magento\Sales\Model\OrderFactory                  $orderFactory
     */
    public function __construct(
        Context              $context,
        Logger               $logger,
        ScopeConfigInterface $scopeConfig,
        Data                 $helper,
        OrderFactory         $orderFactory
    ) {
        $this->helper       = $helper;
        $this->scopeConfig  = $scopeConfig;
        $this->logger       = $logger;
        $this->orderFactory = $orderFactory;
        parent::__construct($context);
    }

    /**
     * Sprawdzenie danych po powrocie z bramki płatniczej
     *
     * @throws \Exception
     */
    public function execute()
    {
        $this->logger->info('BACK:' . __LINE__, ['params' => $this->getRequest()->getParams()]);
        try {
            $params = $this->getRequest()->getParams();

            if (array_key_exists('Hash', $params)) {
                $serviceId = $this->scopeConfig->getValue("payment/bluepayment/service_id");
                $this->logger->info('BACK:' . __LINE__, ['serviceId' => $serviceId]);
                $orderId   = $params['OrderID'];
                $hash      = $params['Hash'];
                $sharedKey = $this->scopeConfig->getValue("payment/bluepayment/shared_key");
                $this->logger->info('BACK:' . __LINE__, ['sharedKey' => $sharedKey]);
                $hashData  = [$serviceId, $orderId, $sharedKey];
                $hashLocal = $this->helper->generateAndReturnHash($hashData);
                $this->logger->info('BACK:' . __LINE__, ['hashLocal' => $hashLocal]);
                if ($hash == $hashLocal) {
                    $this->logger->info('BACK:' . __LINE__ . ' Klucz autoryzacji transakcji poprawny');
                    $this->_redirect('checkout/onepage/success', ['_secure' => true]);
                } else {
                    $this->logger->info('BACK:' . __LINE__ . ' Klucz autoryzacji transakcji jest nieprawidłowy');
                    $this->_redirect('checkout/onepage/failure', ['_secure' => true]);
                }
            } else {
                $this->logger->info('BACK:' . __LINE__ . ' Klucz autoryzacji transakcji nie istnieje');
                $this->_redirect('checkout/onepage/failure', ['_secure' => true]);
            }
        } catch (\Exception $e) {
            $this->messageManager->addError($e->getMessage());
            $this->logger->critical($e);
            $this->_redirect('checkout/onepage/failure', ['_secure' => true]);
        }
    }
}
