<?php

namespace Fintecture\Payment\Gateway;

use Fintecture\Payment\Gateway\Config\Config;
use Fintecture\Payment\Gateway\Http\Sdk;
use Fintecture\Payment\Helper\Fintecture as FintectureHelper;
use Fintecture\Payment\Logger\Logger;
use Magento\Framework\DB\Transaction;
use Magento\Sales\Api\InvoiceRepositoryInterface;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderPaymentRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\TransactionRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface;
use Magento\Sales\Model\Service\InvoiceService;

class HandlePayment
{
    /** @var Logger */
    protected $fintectureLogger;

    /** @var Config */
    protected $config;

    /** @var FintectureHelper */
    protected $fintectureHelper;

    /** @var OrderPaymentRepositoryInterface */
    protected $paymentRepository;

    /** @var BuilderInterface */
    protected $transactionBuilder;

    /** @var TransactionRepositoryInterface */
    protected $transactionRepository;

    /** @var Transaction */
    protected $transaction;

    /** @var OrderRepositoryInterface */
    protected $orderRepository;

    /** @var OrderManagementInterface */
    protected $orderManagement;

    /** @var OrderSender */
    protected $orderSender;

    /** @var InvoiceSender */
    protected $invoiceSender;

    /** @var InvoiceService */
    protected $invoiceService;

    /** @var InvoiceRepositoryInterface */
    protected $invoiceRepository;

    /** @var Sdk */
    protected $sdk;

    public function __construct(
        Logger $fintectureLogger,
        Config $config,
        FintectureHelper $fintectureHelper,
        OrderPaymentRepositoryInterface $paymentRepository,
        BuilderInterface $transactionBuilder,
        TransactionRepositoryInterface $transactionRepository,
        Transaction $transaction,
        OrderRepositoryInterface $orderRepository,
        OrderManagementInterface $orderManagement,
        OrderSender $orderSender,
        InvoiceSender $invoiceSender,
        InvoiceService $invoiceService,
        InvoiceRepositoryInterface $invoiceRepository,
        Sdk $sdk
    ) {
        $this->fintectureLogger = $fintectureLogger;
        $this->config = $config;
        $this->fintectureHelper = $fintectureHelper;
        $this->paymentRepository = $paymentRepository;
        $this->transactionBuilder = $transactionBuilder;
        $this->transaction = $transaction;
        $this->transactionRepository = $transactionRepository;
        $this->orderRepository = $orderRepository;
        $this->orderManagement = $orderManagement;
        $this->orderSender = $orderSender;
        $this->invoiceService = $invoiceService;
        $this->invoiceSender = $invoiceSender;
        $this->invoiceRepository = $invoiceRepository;
        $this->sdk = $sdk;
    }

    public function create(
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
                $order->addCommentToStatusHistory(__('Overpaid order. Amount received: ')->render() . (string) $receivedAmount);
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

        $payment->setTransactionId($params['sessionId']);

        $this->paymentRepository->save($payment);

        $transaction = $this->transactionBuilder->setPayment($payment)
            ->setOrder($order)
            ->setTransactionId($order->getIncrementId() . '-' . time())
            ->setAdditionalInformation([
                Payment\Transaction::RAW_DETAILS => [
                    'amount' => (string) $lastTransactionAmount . ' €',
                    'status' => $params['status'],
                    'sessionId' => $params['sessionId'],
                    'type' => $params['type'],
                ]])
            ->setFailSafe(true)
            ->build(Payment\Transaction::TYPE_CAPTURE);

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

            if (!$order->getCanSendNewEmailFlag()) {
                $order->setCanSendNewEmailFlag(true); // Re-enable email sending (disabled in a SubmitObserver)
            }

            $this->orderRepository->save($order);

            if (!$order->getEmailSent()) {
                $this->orderSender->send($order);
            }
        }
    }

    public function sendInvoice(Order $order, array $params): void
    {
        // Send invoice if order paid
        if ($this->fintectureHelper->isStatusAlreadyFinal($order)
            && $order->canInvoice() && $this->config->isInvoicingActive()) {
            $invoice = $this->invoiceService->prepareInvoice($order);
            $invoice->setRequestedCaptureCase(Order\Invoice::CAPTURE_ONLINE);
            $invoice->setTransactionId($params['sessionId']);
            $invoice->register();
            $invoice->pay();
            $this->invoiceRepository->save($invoice);
            $transaction = $this->transaction
                ->addObject($invoice)
                ->addObject($invoice->getOrder());
            $transaction->save();
            // Send Invoice mail to customer
            $this->invoiceSender->send($invoice);

            $order->setIsCustomerNotified(true);
            $this->orderRepository->save($order);
        }
    }

    public function fail(
        Order $order,
        ?array $params = null,
        ?array $statuses = null,
        bool $webhook = false
    ): void {
        /** @var Order $order */
        if (!$order->getId()) {
            $this->fintectureLogger->error('Failed transaction', ['message' => 'There is no order id found']);

            return;
        }

        if (!$statuses) {
            $statuses = [
                'status' => $this->config->getPaymentFailedStatus(),
                'state' => Order::STATE_CANCELED,
            ];
        }

        try {
            if ($order->canCancel()) {
                if ($this->orderManagement->cancel($order->getEntityId())) {
                    $order->setStatus($statuses['status']);

                    if ($params) {
                        $note = $this->fintectureHelper->getHistoryComment($params, $webhook);
                        $order->addCommentToStatusHistory($note);
                    }

                    $this->orderRepository->save($order);
                }
            }
        } catch (\Exception $e) {
            $this->fintectureLogger->error('Failed transaction', ['exception' => $e]);
        }
    }
}
