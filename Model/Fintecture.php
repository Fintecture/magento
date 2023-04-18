<?php

declare(strict_types=1);

namespace Fintecture\Payment\Model;

use Fintecture\Payment\Helper\Fintecture as FintectureHelper;
use Fintecture\Payment\Logger\Logger as FintectureLogger;
use Fintecture\PisClient;
use Fintecture\Util\Crypto;
use Magento\Checkout\Model\Session;
use Magento\Framework\Api\AttributeValueFactory;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\DB\Transaction;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Framework\Session\SessionManagerInterface;
use Magento\LoginAsCustomerApi\Api\GetLoggedAsCustomerAdminIdInterface;
use Magento\Payment\Helper\Data as PaymentData;
use Magento\Payment\Model\Config as PaymentConfig;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Payment\Model\Method\Logger;
use Magento\Quote\Model\Quote;
use Magento\Sales\Api\CreditmemoRepositoryInterface;
use Magento\Sales\Api\Data\CreditmemoInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\TransactionInterface;
use Magento\Sales\Api\InvoiceRepositoryInterface;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderPaymentRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\TransactionRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface;
use Magento\Sales\Model\Order\RefundAdapterInterface;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\HttpClient\Psr18Client;

/** @phpstan-ignore-next-line : we will refactor the plugin without AbstractMethod */
class Fintecture extends AbstractMethod
{
    public const CODE = 'fintecture';
    public const CONFIG_PREFIX = 'payment/fintecture/';
    public const MODULE_VERSION = '2.2.8';

    private const PAYMENT_COMMUNICATION = 'FINTECTURE-';
    private const REFUND_COMMUNICATION = 'REFUND FINTECTURE-';

    public $_code = 'fintecture';

    protected $_canRefund = true;
    protected $_canRefundInvoicePartial = true;

    /** @var PisClient */
    public $pisClient;

    /** @var EncryptorInterface */
    protected $encryptor;

    /** @var FintectureHelper */
    protected $fintectureHelper;

    /** @var Session */
    protected $checkoutSession;

    /** @var FintectureLogger */
    protected $fintectureLogger;

    /** @var SessionManagerInterface */
    protected $coreSession;

    /** @var OrderSender */
    protected $orderSender;

    /** @var InvoiceSender */
    protected $invoiceSender;

    /** @var InvoiceService */
    protected $invoiceService;

    /** @var ProductMetadataInterface */
    protected $productMetadata;

    /** @var StoreManagerInterface */
    protected $storeManager;

    /** @var PaymentConfig */
    protected $paymentConfig;

    /** @var Transaction */
    protected $transaction;

    /** @var OrderManagementInterface */
    protected $orderManagement;

    /** @var RefundAdapterInterface */
    private $refundAdapter;

    /** @var OrderRepositoryInterface */
    private $orderRepository;

    /** @var OrderPaymentRepositoryInterface */
    private $paymentRepository;

    /** @var InvoiceRepositoryInterface */
    private $invoiceRepository;

    /** @var CreditmemoRepositoryInterface */
    private $creditmemoRepository;

    /** @var BuilderInterface */
    private $transactionBuilder;

    /** @var TransactionRepositoryInterface */
    private $transactionRepository;

    /** @var RemoteAddress */
    private $remoteAddress;

    /** @phpstan-ignore-next-line : ignore error for deprecated registry (Magento side) */
    public function __construct(
        Context $context,
        Registry $registry,
        EncryptorInterface $encryptor,
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
        RefundAdapterInterface $refundAdapter,
        OrderRepositoryInterface $orderRepository,
        OrderPaymentRepositoryInterface $paymentRepository,
        InvoiceRepositoryInterface $invoiceRepository,
        CreditmemoRepositoryInterface $creditmemoRepository,
        BuilderInterface $transactionBuilder,
        TransactionRepositoryInterface $transactionRepository,
        RemoteAddress $remoteAddress
    ) {
        $this->fintectureHelper = $fintectureHelper;
        $this->checkoutSession = $checkoutSession;
        $this->fintectureLogger = $fintectureLogger;
        $this->coreSession = $coreSession;
        $this->productMetadata = $productMetadata;
        $this->orderSender = $orderSender;
        $this->invoiceService = $invoiceService;
        $this->invoiceSender = $invoiceSender;
        $this->storeManager = $storeManager;
        $this->paymentConfig = $paymentConfig;
        $this->transaction = $transaction;
        $this->orderManagement = $orderManagement;
        $this->refundAdapter = $refundAdapter;
        $this->orderRepository = $orderRepository;
        $this->paymentRepository = $paymentRepository;
        $this->invoiceRepository = $invoiceRepository;
        $this->creditmemoRepository = $creditmemoRepository;
        $this->encryptor = $encryptor;
        $this->transactionBuilder = $transactionBuilder;
        $this->transactionRepository = $transactionRepository;
        $this->remoteAddress = $remoteAddress;

        /* @phpstan-ignore-next-line : we will refactor the plugin without AbstractMethod */
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger
        );

        if ($this->validateConfigValue()) {
            try {
                $this->pisClient = new PisClient([
                    'appId' => $this->getAppId(),
                    'appSecret' => $this->getAppSecret(),
                    'privateKey' => $this->getAppPrivateKey(),
                    'environment' => $this->getAppEnvironment(),
                ], new Psr18Client());
            } catch (\Exception $e) {
                $this->fintectureLogger->error('Connection', [
                    'exception' => $e,
                    'message' => "Can't create PISClient",
                ]);
            }
        }
    }

    public function isPisClientInstantiated(): bool
    {
        return $this->pisClient instanceof PisClient;
    }

    public function createPayment(
        Order $order,
        array $params,
        array $statuses,
        bool $webhook = false,
        bool $specificAmount = false
    ): void {
        if (!$order->getId()) {
            $this->fintectureLogger->error('Payment', [
                'message' => 'There is no order id found',
                'webhook' => $webhook,
            ]);

            return;
        }

        /** @var Payment $payment */
        $payment = $order->getPayment();

        if ($specificAmount) {
            // Handle partial payments

            $lastTransactionAmount = round((float) $params['lastTransactionAmount'], 2);
            $receivedAmount = round((float) $params['receivedAmount'], 2);
            $paidAmount = $basePaidAmount = $receivedAmount;

            if ($basePaidAmount > $order->getBaseGrandTotal()) {
                // Overpaid payment
                $order->addCommentToStatusHistory(__('Overpaid order. Amount received: ' . (string) $receivedAmount)->render());
            }
        } else {
            if ($order->getTotalPaid() > 0) {
                // Return as in this case this is a "replay" redirect
                return;
            }

            $lastTransactionAmount = $order->getGrandTotal();
            $paidAmount = $order->getGrandTotal();
            $basePaidAmount = $order->getBaseGrandTotal();
        }

        $payment->setAmountPaid($paidAmount);
        $payment->setBaseAmountPaid($basePaidAmount);

        $this->paymentRepository->save($payment);

        $transaction = $this->transactionBuilder->setPayment($payment)
            ->setOrder($order)
            ->setTransactionId($order->getIncrementId() . '-' . time())
            ->setAdditionalInformation([
                \Magento\Sales\Model\Order\Payment\Transaction::RAW_DETAILS => [
                    'amount' => (string) $lastTransactionAmount . ' €',
                    'status' => $params['status'],
                    'sessionId' => $params['sessionId'],
                ]])
            ->setFailSafe(true)
            ->build(TransactionInterface::TYPE_CAPTURE);

        $this->transactionRepository->save($transaction);

        $order->setTotalPaid($order->getTotalPaid() + $lastTransactionAmount);
        $order->setBaseTotalPaid($order->getBaseTotalPaid() + $lastTransactionAmount);
        $order->setTotalDue(max($order->getTotalDue() - $lastTransactionAmount, 0));
        $order->setBaseTotalDue(max($order->getBaseTotalDue() - $lastTransactionAmount, 0));

        $this->orderRepository->save($order);

        $this->changeOrderState($order, $params, $statuses, $webhook);

        $this->sendInvoice($order, $params);
    }

    public function changeOrderState(
        Order $order,
        array $params,
        array $statuses,
        bool $webhook = false
    ): void {
        $update = false;
        if ($order->getState() !== $statuses['state']) {
            $order->setState($statuses['state']);
            $update = true;
        }

        if ($order->getStatus() !== $statuses['status']) {
            $order->setStatus($statuses['status']);
            $update = true;
        }

        if ($update) {
            $note = $this->fintectureHelper->getHistoryComment($params, $webhook);
            $order->addCommentToStatusHistory($note);

            // Re-enable email sending (disabled in a SubmitObserver)
            $order->setCanSendNewEmailFlag(true);
            $this->orderRepository->save($order);

            $this->orderSender->send($order);
        }
    }

    public function sendInvoice(Order $order, array $params): void
    {
        // Send invoice if order paid
        if ($this->fintectureHelper->isStatusAlreadyFinal($order)
            && $order->canInvoice() && $this->getInvoicingActive()) {
            $invoice = $this->invoiceService->prepareInvoice($order);
            $invoice->setTransactionId($params['sessionId']);
            $invoice->register();
            $this->invoiceRepository->save($invoice);
            $transactionSave = $this->transaction
                ->addObject($invoice)
                ->addObject($invoice->getOrder());
            $transactionSave->save();
            // Send Invoice mail to customer
            $this->invoiceSender->send($invoice);

            $order->setIsCustomerNotified(true);
            $this->orderRepository->save($order);
        }
    }

    public function handleFailedTransaction(
        Order $order,
        array $params,
        ?array $statuses,
        bool $webhook = false
    ): void {
        /** @var Order $order */
        if (!$order->getId()) {
            $this->fintectureLogger->error('Failed transaction', ['message' => 'There is no order id found']);

            return;
        }

        if (!$statuses) {
            $statuses = [
                'status' => $this->fintectureHelper->getPaymentFailedStatus(),
                'state' => Order::STATE_CANCELED,
            ];
        }

        try {
            if ($order->canCancel()) {
                if ($this->orderManagement->cancel($order->getEntityId())) {
                    $order->setStatus($statuses['status']);

                    $note = $this->fintectureHelper->getHistoryComment($params, $webhook);
                    $order->addCommentToStatusHistory($note);

                    $this->orderRepository->save($order);
                }
            }
        } catch (\Exception $e) {
            $this->fintectureLogger->error('Failed transaction', ['exception' => $e]);
        }
    }

    public function createRefund(OrderInterface $order, CreditmemoInterface $creditmemo): void
    {
        if (!$this->isPisClientInstantiated()) {
            throw new \Exception('PISClient not instantiated');
        }

        /** @var Order $order */
        $incrementOrderId = $order->getIncrementId();

        $sessionId = $this->fintectureHelper->getSessionIdByOrderId($order->getId());
        if (!$sessionId) {
            $this->fintectureLogger->error('Refund', [
                'message' => "Can't get session id of order",
                'incrementOrderId' => $incrementOrderId,
            ]);

            throw new \Exception("Can't get session id of order");
        }

        $creditmemos = $order->getCreditmemosCollection();
        if (!$creditmemos) {
            $this->fintectureLogger->error('Refund', [
                'message' => 'No creditmemos found',
                'incrementOrderId' => $incrementOrderId,
            ]);

            throw new \Exception('No creditmemos found');
        }
        $nbCreditmemos = $creditmemos->count() + 1;

        $amount = (float) $creditmemo->getBaseGrandTotal();
        if (!$amount) {
            $this->fintectureLogger->error('Refund', [
                'message' => 'No amount on creditmemo',
                'incrementOrderId' => $incrementOrderId,
            ]);

            throw new \Exception('No amount on creditmemo');
        }

        $this->fintectureLogger->info('Refund', [
            'message' => 'Refund started',
            'incrementOrderId' => $incrementOrderId,
            'amount' => $amount,
            'sessionId' => $sessionId,
        ]);

        $data = [
            'meta' => [
                'session_id' => $sessionId,
            ],
            'data' => [
                'attributes' => [
                    'amount' => (string) round($amount, 2),
                    'communication' => self::REFUND_COMMUNICATION . $incrementOrderId . '-' . $nbCreditmemos,
                ],
            ],
        ];

        try {
            $creditmemoTransactionId = $creditmemo->getTransactionId();
            if ($creditmemoTransactionId) {
                $state = Crypto::encodeToBase64([
                    'order_id' => $order->getIncrementId(),
                    'creditmemo_transaction_id' => $creditmemoTransactionId,
                ]);

                /** @phpstan-ignore-next-line */
                $pisToken = $this->pisClient->token->generate();
                if (!$pisToken->error) {
                    $this->pisClient->setAccessToken($pisToken); // set token of PIS client
                } else {
                    throw new \Exception($pisToken->errorMsg);
                }

                /** @phpstan-ignore-next-line */
                $apiResponse = $this->pisClient->refund->generate($data, $state);
                if (!$apiResponse->error) {
                    if ($order->canHold()) {
                        $order->hold();
                    }
                    $order->addCommentToStatusHistory(__('The refund link has been send.')->render());
                    $this->orderRepository->save($order);

                    $this->fintectureLogger->info('Refund', [
                        'message' => 'The refund link has been send',
                        'incrementOrderId' => $incrementOrderId,
                    ]);
                } else {
                    $this->fintectureLogger->error('Refund', [
                        'message' => 'Invalid API response',
                        'incrementOrderId' => $incrementOrderId,
                        'response' => $apiResponse->errorMsg,
                    ]);
                    throw new \Exception($apiResponse->errorMsg);
                }
            } else {
                $this->fintectureLogger->error('Refund', [
                    'message' => 'State of creditmemo if empty',
                    'incrementOrderId' => $incrementOrderId,
                ]);
            }
        } catch (\Exception $e) {
            $this->fintectureLogger->error('Refund', [
                'exception' => $e,
                'incrementOrderId' => $incrementOrderId,
            ]);
            throw new LocalizedException(
                __('Sorry, something went wrong. Please try again later.')
            );
        }
    }

    public function applyRefund(OrderInterface $order, string $creditmemoTransactionId): bool
    {
        try {
            /** @var Order $order */
            $creditmemos = $order->getCreditmemosCollection();
            if (!$creditmemos) {
                throw new \Exception("Can't find any creditmemo on the order");
            }

            /** @var Creditmemo $creditmemo */
            $creditmemo = $creditmemos
                ->addFieldToFilter('transaction_id', $creditmemoTransactionId)
                ->getLastItem();
        } catch (\Exception $e) {
            $order->addCommentToStatusHistory(__('The refund has failed.')->render());
            $this->orderRepository->save($order);

            $this->fintectureLogger->error('Apply refund', [
                'message' => "Can't find credit memo associated to order",
                'creditmemoId' => $creditmemoTransactionId,
                'incrementOrderId' => $order->getIncrementId(),
                'exception' => $e,
            ]);

            return false;
        }

        try {
            $creditmemo->setState(Creditmemo::STATE_REFUNDED);

            /** @var Order $order */
            $order = $this->refundAdapter->refund($creditmemo, $creditmemo->getOrder(), true);

            if ($order->canUnhold()) {
                $order->unhold();
            }
            $order->addCommentToStatusHistory(__('The refund has been made.')->render());

            $this->orderRepository->save($order);
            $this->creditmemoRepository->save($creditmemo);

            $this->fintectureLogger->info('Refund completed', [
                'creditmemoId' => $creditmemoTransactionId,
                'incrementOrderId' => $order->getIncrementId(),
            ]);

            return true;
        } catch (\Exception $e) {
            $order->addCommentToStatusHistory(__('The refund has failed.')->render());
            $this->orderRepository->save($order);

            $this->fintectureLogger->error('Apply refund', [
                'message' => "Can't apply refund",
                'creditmemoId' => $creditmemoTransactionId,
                'incrementOrderId' => $order->getIncrementId(),
                'exception' => $e,
            ]);
        }

        return false;
    }

    public function getShopName(): ?string
    {
        return $this->_scopeConfig->getValue('general/store_information/name', ScopeInterface::SCOPE_STORE);
    }

    public function getAppId(string $environment = null): ?string
    {
        $environment = $environment ?: $this->getAppEnvironment();

        return $this->_scopeConfig->getValue(static::CONFIG_PREFIX . 'fintecture_app_id_' . $environment, ScopeInterface::SCOPE_STORE);
    }

    public function getAppSecret(string $environment = null, string $scope = ScopeInterface::SCOPE_STORE, int $scopeId = null): ?string
    {
        $environment = $environment ?: $this->getAppEnvironment();

        return $this->_scopeConfig->getValue(static::CONFIG_PREFIX . 'fintecture_app_secret_' . $environment, $scope, $scopeId);
    }

    public function getAppPrivateKey(string $environment = null, string $scope = ScopeInterface::SCOPE_STORE, int $scopeId = null): ?string
    {
        $environment = $environment ?: $this->getAppEnvironment();
        $privateKey = $this->_scopeConfig->getValue(self::CONFIG_PREFIX . 'custom_file_upload_' . $environment, $scope, $scopeId);

        return $privateKey ? $this->encryptor->decrypt($privateKey) : null;
    }

    public function getAppEnvironment(): ?string
    {
        return $this->_scopeConfig->getValue(static::CONFIG_PREFIX . 'environment', ScopeInterface::SCOPE_STORE);
    }

    public function getBankType(): ?string
    {
        return $this->_scopeConfig->getValue(static::CONFIG_PREFIX . 'general/bank_type', ScopeInterface::SCOPE_STORE);
    }

    public function getActive(): int
    {
        return (int) $this->_scopeConfig->isSetFlag(static::CONFIG_PREFIX . 'active', ScopeInterface::SCOPE_STORE);
    }

    public function getShowLogo(): int
    {
        return (int) $this->_scopeConfig->isSetFlag(static::CONFIG_PREFIX . 'general/show_logo', ScopeInterface::SCOPE_STORE);
    }

    public function getExpirationActive(): bool
    {
        return $this->_scopeConfig->isSetFlag(static::CONFIG_PREFIX . 'expiration_active', ScopeInterface::SCOPE_STORE);
    }

    public function getExpirationAfter(): ?int
    {
        return (int) $this->_scopeConfig->getValue(static::CONFIG_PREFIX . 'expiration_after', ScopeInterface::SCOPE_STORE);
    }

    public function getInvoicingActive(): bool
    {
        return $this->_scopeConfig->isSetFlag(static::CONFIG_PREFIX . 'invoicing_active', ScopeInterface::SCOPE_STORE);
    }

    public function getAlternativeMethodActive(): bool
    {
        return $this->_scopeConfig->isSetFlag(static::CONFIG_PREFIX . 'alternative_method_active', ScopeInterface::SCOPE_STORE);
    }

    public function getAlternativeMethod(): ?string
    {
        return $this->_scopeConfig->getValue(static::CONFIG_PREFIX . 'alternative_method', ScopeInterface::SCOPE_STORE);
    }

    public function generatePayload(Quote $quote, string $type, string $method = ''): array
    {
        $payload = [];
        $billingAddress = $quote->getBillingAddress();

        $phone = $billingAddress->getTelephone();
        if (strlen($phone) > 1 && substr($phone, 0, 1) === '0') {
            $phone = substr($phone, 1);
        }

        $payload = [
            'meta' => [
                'psu_name' => $billingAddress->getName(),
                'psu_email' => $billingAddress->getEmail(),
                'psu_company' => $billingAddress->getCompany(),
                'psu_phone' => $phone,
                'psu_phone_prefix' => '+33',
                'psu_ip' => $this->remoteAddress->getRemoteAddress(),
                'psu_address' => [
                    'street' => implode(' ', $billingAddress->getStreet()),
                    'zip' => $billingAddress->getPostcode(),
                    'city' => $billingAddress->getCity(),
                    'country' => $billingAddress->getCountryId(),
                ],
            ],
            'data' => [
                'type' => $type,
                'attributes' => [
                    'amount' => (string) round((float) $quote->getBaseGrandTotal(), 2),
                    'currency' => $quote->getQuoteCurrencyCode(),
                    'communication' => self::PAYMENT_COMMUNICATION . $quote->getReservedOrderId(),
                ],
            ],
        ];

        // Handle order expiration if enabled
        if ($this->getExpirationActive()) {
            $minutes = $this->getExpirationAfter();
            if (is_int($minutes) && $minutes >= 3 && $minutes <= 9999) {
                $payload['meta']['expiry'] = $minutes * 60;
            } else {
                $this->fintectureLogger->error('Payload', [
                    'message' => 'Expiration time must be between 3 and 9999 minutes.',
                    'minutes' => 'Current expiration time: ' . $minutes,
                ]);
            }
        }

        // Handle method for RTP
        if ($type === 'REQUEST_TO_PAY' && !empty($method)) {
            $payload['meta']['method'] = $method;
        }

        return $payload;
    }

    public function getPaymentRedirectUrl(Quote $quote): string
    {
        if (!$this->validateConfigValue()) {
            throw new LocalizedException(
                __('Something went wrong try another payment method!')
            );
        }

        $alternativeMethodActive = false;
        if (interface_exists(GetLoggedAsCustomerAdminIdInterface::class)) {
            $getLoggedAsCustomerAdminId = ObjectManager::getInstance()->get(GetLoggedAsCustomerAdminIdInterface::class);
            if ($getLoggedAsCustomerAdminId) {
                $alternativeMethodActive = (bool) $getLoggedAsCustomerAdminId->execute() && $this->getAlternativeMethodActive();
            }
        }

        try {
            if (!$alternativeMethodActive) {
                // Connect
                $data = $this->generatePayload($quote, 'PIS');
                $redirect = $this->getConnectRedirect($quote, $data);
            } else {
                // RTP
                $alternativeMethod = $this->getAlternativeMethod();
                if ($alternativeMethod === 'send') {
                    // SMS/EMAIL
                    $redirect = $this->getSendRedirect($quote);
                } else {
                    // QR CODE
                    $data = $this->generatePayload($quote, 'REQUEST_TO_PAY');
                    $redirect = $this->getQRCodeRedirect($quote, $data);
                }
            }

            return $redirect['url'];
        } catch (\Exception $e) {
            $this->fintectureLogger->error('Connect session', [
                'exception' => $e,
                'reservedIncrementOrderId' => $quote->getReservedOrderId(),
            ]);

            $this->checkoutSession->restoreQuote();
            throw new \Exception($e->getMessage());
        }
    }

    private function getConnectRedirect(Quote $quote, array $data): array
    {
        if (!$this->isPisClientInstantiated()) {
            throw new \Exception('PISClient not instantiated');
        }

        $state = Crypto::encodeToBase64(['order_id' => $quote->getReservedOrderId()]);
        $redirectUrl = $this->getResponseUrl();
        $originUrl = $this->getOriginUrl();
        $psuType = $this->getBankType();
        if (!$psuType) {
            $psuType = 'all';
        }

        /** @phpstan-ignore-next-line */
        $pisToken = $this->pisClient->token->generate();
        if (!$pisToken->error) {
            $this->pisClient->setAccessToken($pisToken); // set token of PIS client
        } else {
            throw new \Exception($pisToken->errorMsg);
        }

        /** @phpstan-ignore-next-line */
        $apiResponse = $this->pisClient->connect->generate(
            $data,
            $state,
            $redirectUrl,
            $originUrl,
            null,
            null,
            [
                'x-psu-type' => $psuType,
            ]
        );

        if ($apiResponse->error) {
            $this->fintectureLogger->error('Connect session', [
                'message' => 'Error building connect URL',
                'reservedIncrementOrderId' => $quote->getReservedOrderId(),
                'response' => $apiResponse->errorMsg,
            ]);
            $this->checkoutSession->restoreQuote();
            throw new \Exception($apiResponse->errorMsg);
        }

        return [
            'sessionId' => $apiResponse->meta->session_id ?? '',
            'url' => $apiResponse->meta->url ?? '',
        ];
    }

    private function getQRCodeRedirect(Quote $quote, array $data): array
    {
        if (!$this->isPisClientInstantiated()) {
            throw new \Exception('PISClient not instantiated');
        }

        /** @phpstan-ignore-next-line */
        $pisToken = $this->pisClient->token->generate();
        if (!$pisToken->error) {
            $this->pisClient->setAccessToken($pisToken); // set token of PIS client
        } else {
            throw new \Exception($pisToken->errorMsg);
        }

        $state = Crypto::encodeToBase64(['order_id' => $quote->getReservedOrderId()]);
        /** @phpstan-ignore-next-line */
        $apiResponse = $this->pisClient->requestToPay->generate($data, 'fr', null, $state);
        if ($apiResponse->error) {
            $this->fintectureLogger->error('Connect session', [
                'message' => 'Error building connect URL',
                'reservedIncrementOrderId' => $quote->getReservedOrderId(),
                'response' => $apiResponse->errorMsg,
            ]);
            $this->checkoutSession->restoreQuote();
            throw new \Exception($apiResponse->errorMsg);
        }

        $sessionId = $apiResponse->meta->session_id ?? '';

        $params = [
            'url' => urlencode($apiResponse->meta->url ?? ''),
            'reference' => $data['data']['attributes']['communication'],
            'amount' => $data['data']['attributes']['amount'],
            'currency' => $data['data']['attributes']['currency'],
            'session_id' => $sessionId,
            'confirm' => 0,
        ];

        return [
            'sessionId' => $sessionId,
            'url' => $this->getQrCodeUrl() . '?' . http_build_query($params),
        ];
    }

    private function getSendRedirect(Quote $quote): array
    {
        return [
            'url' => $this->getSendUrl() . '?step=1&quoteId=' . $quote->getId(),
        ];
    }

    public function validateConfigValue(): bool
    {
        if (!$this->getAppEnvironment()
            || !$this->getAppPrivateKey()
            || !$this->getAppId()
            || !$this->getAppSecret()
        ) {
            return false;
        }

        return true;
    }

    public function getResponseUrl(): string
    {
        return $this->fintectureHelper->getUrl('fintecture/standard/response');
    }

    public function getOriginUrl(): string
    {
        return $this->fintectureHelper->getUrl('fintecture/standard/response');
    }

    public function getRedirectUrl(): string
    {
        return $this->fintectureHelper->getUrl('fintecture/standard/redirect');
    }

    public function getQrCodeUrl(): string
    {
        return $this->fintectureHelper->getUrl('fintecture/standard/qrcode');
    }

    public function getSendUrl(): string
    {
        return $this->fintectureHelper->getUrl('fintecture/standard/send');
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
            'shop_cms_version' => $this->getMagentoVersion(),
            'module_version' => self::MODULE_VERSION,
            'module_position' => '', // TODO: find way to get to find position
            'shop_payment_methods' => $this->getNumberOfActivePaymentMethods(),
            'module_enabled' => $this->getActive(),
            'module_production' => $this->getAppEnvironment() === Environment::ENVIRONMENT_PRODUCTION ? 1 : 0,
            'module_sandbox_app_id' => $this->getAppId(Environment::ENVIRONMENT_SANDBOX),
            'module_production_app_id' => $this->getAppId(Environment::ENVIRONMENT_PRODUCTION),
            'module_branding' => $this->getShowLogo(),
        ];
    }

    public function getMagentoVersion(): string
    {
        $version = $this->productMetadata->getVersion();
        if ($version === 'UNKNOWN') {
            $this->fintectureLogger->debug("Can't detect Magento version.");

            return 'UNKNOWN';
        }

        return $version;
    }
}
