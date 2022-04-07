<?php

declare(strict_types=1);

namespace Fintecture\Payment\Model;

use Exception;
use Fintecture\Payment\Gateway\Client;
use Fintecture\Payment\Helper\Fintecture as FintectureHelper;
use Fintecture\Payment\Logger\Logger as FintectureLogger;
use Magento\Checkout\Model\Session;
use Magento\Framework\Api\AttributeValueFactory;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\DB\Transaction;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Framework\Session\SessionManagerInterface;
use Magento\Payment\Helper\Data as PaymentData;
use Magento\Payment\Model\Config as PaymentConfig;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Payment\Model\Method\Logger;
use Magento\Sales\Api\Data\TransactionInterface;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Status\HistoryFactory;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

class Fintecture extends AbstractMethod
{
    const MODULE_VERSION = '1.2.7';
    const PAYMENT_FINTECTURE_CODE = 'fintecture';
    const CONFIG_PREFIX = 'payment/fintecture/';

    public $_code = 'fintecture';

    /** @var FintectureHelper */
    protected $fintectureHelper;

    protected $environment = Environment::ENVIRONMENT_PRODUCTION;

    /** @var Session $checkoutSession */
    protected $checkoutSession;

    /**  @var FintectureLogger */
    protected $fintectureLogger;

    /** @var SessionManagerInterface $coreSession */
    protected $coreSession;

    /** @var OrderSender $orderSender */
    protected $orderSender;

    /** @var InvoiceSender $invoiceSender */
    protected $invoiceSender;

    /** @var InvoiceService $invoiceService */
    protected $invoiceService;

    /** @var ProductMetadataInterface $productMetadata */
    protected $productMetadata;

    /** @var StoreManagerInterface $storeManager */
    protected $storeManager;

    /** @var PaymentConfig $paymentConfig */
    protected $paymentConfig;

    /** @var Transaction $transaction */
    protected $transaction;

    /** @var OrderManagementInterface $orderManagement */
    protected $orderManagement;

    /** @var HistoryFactory orderStatusHistoryFactory */
    private $orderStatusHistoryFactory;

    public function __construct(
        Context $context,
        Registry $registry,
        ExtensionAttributesFactory $extensionFactory,
        AttributeValueFactory $customAttributeFactory,
        PaymentData $paymentData,
        ScopeConfigInterface $scopeConfig,
        Logger $logger,
        FintectureHelper $fintectureHelper,
        Session $checkoutSession,
        FintectureLogger $fintectureLogger,
        SessionManagerInterface $coreSession,
        OrderSender $orderSender,
        InvoiceSender $invoiceSender,
        InvoiceService $invoiceService,
        ProductMetadataInterface $productMetadata,
        StoreManagerInterface $storeManager,
        PaymentConfig $paymentConfig,
        Transaction $transaction,
        OrderManagementInterface $orderManagement,
        HistoryFactory $orderStatusHistoryFactory
    ) {
        $this->fintectureHelper = $fintectureHelper;
        $this->checkoutSession = $checkoutSession;
        $this->fintectureLogger = $fintectureLogger;
        $this->coreSession = $coreSession;
        $this->productMetadata = $productMetadata;
        $this->orderSender = $orderSender;
        $this->invoiceSender = $invoiceSender;
        $this->invoiceService = $invoiceService;
        $this->storeManager = $storeManager;
        $this->paymentConfig = $paymentConfig;
        $this->transaction = $transaction;
        $this->orderManagement = $orderManagement;
        $this->orderStatusHistoryFactory = $orderStatusHistoryFactory;

        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger
        );

        $this->environment = $this->_scopeConfig->getValue(static::CONFIG_PREFIX . 'environment', ScopeInterface::SCOPE_STORE);
    }

    public function handleSuccessTransaction($order, $response)
    {
        /** @var Order $order */
        if (!$order->getId()) {
            $this->fintectureLogger->debug('There is no order id found');
            return;
        }

        $status = $this->fintectureHelper->getOrderStatusBasedOnPaymentStatus($response);
        $orderStatus = $status['status'] ?? '';
        $orderState = $status['state'] ?? '';

        // Don't update order if state has already been set
        if ($order->getState() === $orderState) {
            $this->fintectureLogger->debug('State is already set');
            return;
        }

        $metaSessionId = $response['meta']['session_id'] ?? '';
        $metaStatus = $response['meta']['status'] ?? '';

        $order->getPayment()->setTransactionId($metaSessionId);
        $order->getPayment()->setLastTransId($metaSessionId);
        $order->getPayment()->addTransaction(TransactionInterface::TYPE_ORDER);
        $order->getPayment()->setIsTransactionClosed(0);
        $order->getPayment()->setAdditionalInformation(['status' => $metaStatus, 'sessionId' => $metaSessionId]);
        $order->getPayment()->place();

        $order->setStatus($orderStatus);
        $order->setState($orderState);
        $order->save();

        $this->orderSender->send($order);

        if ($order->canInvoice()) {
            $invoice = $this->invoiceService->prepareInvoice($order);
            $invoice->register();
            $invoice->save();
            $transactionSave = $this->transaction
                ->addObject(
                    $invoice
                )->addObject(
                    $invoice->getOrder()
                );
            $transactionSave->save();
            // Send Invoice mail to customer
            $this->invoiceSender->send($invoice);
            $order->addStatusHistoryComment($this->fintectureHelper->getStatusHistoryComment($response))
                ->setIsCustomerNotified(true)
                ->save();
        }
    }

    public function handleFailedTransaction($order, $response)
    {
        /** @var Order $order */
        if (!$order->getId()) {
            $this->fintectureLogger->debug('There is no order id found');
            return;
        }

        try {
            if ($order->canCancel()) {
                if ($this->orderManagement->cancel($order->getEntityId())) {
                    $sessionId = $response['meta']['session_id'] ?? '';
                    $status = $response['meta']['status'] ?? '';

                    $order->getPayment()->setTransactionId($sessionId);
                    $order->getPayment()->setLastTransId($sessionId);
                    $order->getPayment()->setAdditionalInformation(['status' => $status, 'sessionId' => $sessionId]);

                    $note = $this->fintectureHelper->getStatusHistoryComment($status);

                    $orderStatusHistory = $this->orderStatusHistoryFactory->create()
                            ->setParentId($order->getEntityId())
                            ->setEntityName('order')
                            ->setStatus(Order::STATE_CANCELED)
                            ->setComment($note);
                    $this->orderManagement->addComment($order->getEntityId(), $orderStatusHistory);
                }
            }
        } catch (Exception $e) {
            $this->fintectureLogger->debug($e->getMessage(), $e->getTrace());
        }
    }

    public function handleHoldedTransaction($order, $response)
    {
        /** @var Order $order */
        if (!$order->getId()) {
            $this->fintectureLogger->debug('There is no order id found');
            return;
        }

        $status = $this->fintectureHelper->getOrderStatusBasedOnPaymentStatus($response);
        $orderStatus = $status['status'] ?? '';
        $orderState = $status['state'] ?? '';

        // Don't update order if state has already been set
        if ($order->getState() === $orderState) {
            $this->fintectureLogger->debug('State is already set');
            return;
        }

        try {
            $metaSessionId = $response['meta']['session_id'] ?? '';
            $metaStatus = $response['meta']['status'] ?? '';

            $order->getPayment()->setTransactionId($metaSessionId);
            $order->getPayment()->setLastTransId($metaSessionId);
            $order->getPayment()->setAdditionalInformation(['status' => $metaStatus, 'sessionId' => $metaSessionId]);

            $note = $this->fintectureHelper->getStatusHistoryComment($response);

            $order->setState($orderState);
            $order->setStatus($orderStatus);
            $order->setCustomerNoteNotify(false);
            $order->addStatusHistoryComment($note);
            $order->save();
        } catch (Exception $e) {
            $this->fintectureLogger->debug($e->getMessage(), $e->getTrace());
        }
    }

    public function getLastPaymentStatusResponse()
    {
        $lastPaymentSessionId = $this->coreSession->getPaymentSessionId();
        $gatewayClient = $this->getGatewayClient();
        $apiResponse = $gatewayClient->getPayment($lastPaymentSessionId);

        return $apiResponse;
    }

    public function getGatewayClient()
    {
        $gatewayClient = new Client(
            [
                'fintectureApiUrl' => $this->getFintectureApiUrl(),
                'fintecturePrivateKey' => $this->getAppPrivateKey(),
                'fintectureAppId' => $this->getAppId(),
                'fintectureAppSecret' => $this->getAppSecret(),
            ]
        );
        return $gatewayClient;
    }

    public function getFintectureApiUrl()
    {
        return $this->environment === 'sandbox'
            ? 'https://api-sandbox.fintecture.com/' : 'https://api.fintecture.com/';
    }

    public function getAppPrivateKey()
    {
        $objectManager = ObjectManager::getInstance();
        $configReader = $objectManager->create('Magento\Framework\Module\Dir\Reader');
        $modulePath = $configReader->getModuleDir('etc', 'Fintecture_Payment');

        $fileDirPath = $modulePath . '/lib/app_private_key_' . $this->environment;
        $fileName = $this->findKeyfile($fileDirPath);

        if (!$fileName) {
            return '';
        }

        return file_get_contents(
            $modulePath
            . DIRECTORY_SEPARATOR
            . 'lib'
            . DIRECTORY_SEPARATOR
            . 'app_private_key_' . $this->environment
            . DIRECTORY_SEPARATOR
            . $fileName
        );
    }

    private function findKeyfile($dir_path)
    {
        $files = scandir($dir_path);
        foreach ($files as $file) {
            if (strpos($file, '.pem') !== false) {
                return $file;
            }
        }
        return false;
    }

    public function getShopName()
    {
        return $this->_scopeConfig->getValue('general/store_information/name', ScopeInterface::SCOPE_STORE);
    }

    public function getAppId()
    {
        return $this->_scopeConfig->getValue(static::CONFIG_PREFIX . 'fintecture_app_id_' . $this->environment, ScopeInterface::SCOPE_STORE);
    }

    public function getAppSecret()
    {
        return $this->_scopeConfig->getValue(static::CONFIG_PREFIX . 'fintecture_app_secret_' . $this->environment, ScopeInterface::SCOPE_STORE);
    }

    public function isRewriteModeActive()
    {
        return $this->_scopeConfig->getValue('web/seo/use_rewrites', ScopeInterface::SCOPE_STORE) === "1";
    }

    public function getBankType()
    {
        return $this->_scopeConfig->getValue('payment/fintecture/general/bank_type', ScopeInterface::SCOPE_STORE);
    }

    public function getActive()
    {
        return (int) $this->_scopeConfig->isSetFlag('payment/fintecture/active', ScopeInterface::SCOPE_STORE);
    }

    public function getShowLogo()
    {
        return (int) $this->_scopeConfig->isSetFlag('payment/fintecture/general/show_logo', ScopeInterface::SCOPE_STORE);
    }

    public function getPaymentGatewayRedirectUrl(): string
    {
        return $this->buildCheckoutURL();
    }

    public function buildCheckoutURL(): string
    {
        $this->validateConfigValue();

        $lastRealOrder = $this->checkoutSession->getLastRealOrder();
        if (!$lastRealOrder) {
            $this->fintectureLogger->debug('No order found in session, please try again');
            throw new LocalizedException(__('No order found in session, please try again'));
        }

        $billingAddress = $lastRealOrder->getBillingAddress();
        if (!$billingAddress) {
            $this->fintectureLogger->debug('No billing address found in order, please try again');
            throw new LocalizedException(__('No billing address found in order, please try again'));
        }

        $data = [
            'meta' => [
                'psu_name' => $billingAddress->getName(),
                'psu_email' => $billingAddress->getEmail(),
                'psu_company' => $billingAddress->getCompany(),
                'psu_phone' => $billingAddress->getTelephone(),
                'psu_ip' => $lastRealOrder->getRemoteIp(),
                'psu_address' => [
                    'street' => implode(' ', $billingAddress->getStreet()),
                    'number' => '',
                    'complement' => '',
                    'zip' => $billingAddress->getPostcode(),
                    'city' => $billingAddress->getCity(),
                    'country' => $billingAddress->getCountryId(),
                ],
            ],
            'data' => [
                'type' => 'PIS',
                'attributes' => [
                    'amount' => number_format($lastRealOrder->getBaseTotalDue(), 2, '.', ''),
                    'currency' => $lastRealOrder->getOrderCurrencyCode(),
                    'communication' => 'FINTECTURE-' . $lastRealOrder->getIncrementId()
                ],
            ],
        ];

        try {
            $gatewayClient = $this->getGatewayClient();
            $state = $gatewayClient->getUid();
            $isRewriteModeActive = $this->isRewriteModeActive();
            $redirectUrl = $this->getResponseUrl();
            $originUrl = $this->getOriginUrl();
            $psuType = $this->getBankType();

            $apiResponse = $gatewayClient->generateConnectURL($data, $isRewriteModeActive, $redirectUrl, $originUrl, $psuType, $state);

            if (!isset($apiResponse['meta'])) {
                $this->fintectureLogger->debug('Error building Checkout URL ' . json_encode($apiResponse['meta']['errors'] ?? '', JSON_UNESCAPED_UNICODE));
                $this->checkoutSession->restoreQuote();
                throw new LocalizedException(
                    __('Sorry, something went wrong. Please try again later.')
                );
            } else {
                $sessionId = $apiResponse['meta']['session_id'] ?? '';

                $lastRealOrder->setFintecturePaymentSessionId($sessionId);
                $lastRealOrder->setFintecturePaymentCustomerId($sessionId);
                try {
                    $lastRealOrder->save();
                } catch (Exception $e) {
                    $this->fintectureLogger->debug($e->getMessage(), $e->getTrace());
                }

                $this->coreSession->setPaymentSessionId($sessionId);
                return $apiResponse['meta']['url'] ?? '';
            }
        } catch (Exception $e) {
            $this->fintectureLogger->debug($e->getMessage(), $e->getTrace());

            $this->checkoutSession->restoreQuote();
            throw new LocalizedException(
                __($e->getMessage())
            );
        }
    }

    public function validateConfigValue(): void
    {
        if (!$this->getFintectureApiUrl()
            || !$this->getAppPrivateKey()
            || !$this->getAppId()
            || !$this->getAppSecret()
        ) {
            throw new LocalizedException(
                __('Something went wrong try another payment method!')
            );
        }
    }

    public function getResponseUrl(): string
    {
        return $this->fintectureHelper->getUrl('fintecture/standard/response');
    }

    public function getOriginUrl(): string
    {
        return $this->fintectureHelper->getUrl('checkout/') . '#payment';
    }

    public function getRedirectUrl(): string
    {
        return $this->fintectureHelper->getUrl('fintecture/standard/redirect');
    }

    public function getBeneficiaryName()
    {
        return $this->_scopeConfig->getValue('beneficiary_name', ScopeInterface::SCOPE_STORE);
    }

    public function getBeneficiaryStreet()
    {
        return $this->_scopeConfig->getValue('beneficiary_street', ScopeInterface::SCOPE_STORE);
    }

    public function getBeneficiaryNumber()
    {
        return $this->_scopeConfig->getValue('beneficiary_number', ScopeInterface::SCOPE_STORE);
    }

    public function getBeneficiaryCity()
    {
        return $this->_scopeConfig->getValue('beneficiary_city', ScopeInterface::SCOPE_STORE);
    }

    public function getBeneficiaryZip()
    {
        return $this->_scopeConfig->getValue('beneficiary_zip', ScopeInterface::SCOPE_STORE);
    }

    public function getBeneficiaryCountry()
    {
        return $this->_scopeConfig->getValue('beneficiary_country', ScopeInterface::SCOPE_STORE);
    }

    public function getBeneficiaryIban()
    {
        return $this->_scopeConfig->getValue('beneficiary_iban', ScopeInterface::SCOPE_STORE);
    }

    public function getBeneficiarySwiftBic()
    {
        return $this->_scopeConfig->getValue('beneficiary_swift_bic', ScopeInterface::SCOPE_STORE);
    }

    public function getBeneficiaryBankName()
    {
        return $this->_scopeConfig->getValue('beneficiary_bank_name', ScopeInterface::SCOPE_STORE);
    }

    public function getNumberOfActivePaymentMethods(): int
    {
        return count($this->paymentConfig->getActiveMethods());
    }

    public function getConfigurationSummary(): array
    {
        return [
            'type' => 'php-mg-1',
            'php_version' => PHP_VERSION,
            'shop_name' => $this->getShopName(),
            'shop_domain' => $this->storeManager->getStore()->getBaseUrl(),
            'shop_cms' => 'magento',
            'shop_cms_version' => $this->productMetadata->getVersion(),
            'module_version' => self::MODULE_VERSION,
            'module_position' => '', // TODO: find way to get to find position
            'shop_payment_methods' => $this->getNumberOfActivePaymentMethods(),
            'module_enabled' => $this->getActive(),
            'module_production' => $this->environment === Environment::ENVIRONMENT_PRODUCTION ? 1 : 0,
            'module_sandbox_app_id' => $this->getAppId(Environment::ENVIRONMENT_SANDBOX),
            'module_production_app_id' => $this->getAppId(Environment::ENVIRONMENT_PRODUCTION),
            'module_branding' => $this->getShowLogo()
        ];
    }
}
