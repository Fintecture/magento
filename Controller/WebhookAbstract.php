<?php

declare(strict_types=1);

namespace Fintecture\Payment\Controller;

use Fintecture\Payment\Gateway\Config\Config;
use Fintecture\Payment\Gateway\HandlePayment;
use Fintecture\Payment\Gateway\HandleRefund;
use Fintecture\Payment\Helper\Fintecture as FintectureHelper;
use Fintecture\Payment\Logger\Logger as FintectureLogger;
use Fintecture\Util\Validation;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;

abstract class WebhookAbstract implements CsrfAwareActionInterface
{
    /** @var FintectureLogger */
    protected $fintectureLogger;

    /** @var FintectureHelper */
    protected $fintectureHelper;

    /** @var RawFactory */
    protected $resultRawFactory;

    /** @var ResultFactory */
    protected $resultFactory;

    /** @var CollectionFactory */
    protected $orderCollectionFactory;

    /** @var Http */
    protected $request;

    /** @var HandlePayment */
    protected $handlePayment;

    /** @var HandleRefund */
    protected $handleRefund;

    /** @var Config */
    protected $config;

    /** @var array<string> */
    protected const ALLOWED_WEBHOOK_TYPES = [
        'PayByBank',
        'Refund',
        'BuyNowPayLater',
        'ManualTransfer',
        'RequestToPay'
    ];

    public function __construct(
        FintectureLogger $fintectureLogger,
        FintectureHelper $fintectureHelper,
        RawFactory $resultRawFactory,
        CollectionFactory $orderCollectionFactory,
        Http $request,
        HandlePayment $handlePayment,
        HandleRefund $handleRefund,
        Config $config
    ) {
        $this->fintectureLogger = $fintectureLogger;
        $this->fintectureHelper = $fintectureHelper;
        $this->resultRawFactory = $resultRawFactory;
        $this->orderCollectionFactory = $orderCollectionFactory;
        $this->request = $request;
        $this->handlePayment = $handlePayment;
        $this->handleRefund = $handleRefund;
        $this->config = $config;
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    /**
     * @return bool
     *
     * session_id=b2bca2bcd3b64a32a7da0766df59a7d2
     * &status=payment_created
     * &customer_id=1ef74051a77673de120820fb370dc382
     * &provider=provider
     * &state=thisisastate
     */
    public function validateWebhook(): bool
    {
        $body = file_get_contents('php://input');
        if (!$body) {
            return false;
        }

        if (!isset($_SERVER['HTTP_DIGEST']) || !isset($_SERVER['HTTP_SIGNATURE'])) {
            return false;
        }
        $digest = $_SERVER['HTTP_DIGEST'];
        $signature = $_SERVER['HTTP_SIGNATURE'];

        return Validation::validSignature($body, $digest, $signature);
    }

    abstract public function execute();
}
