<?php

declare(strict_types=1);

namespace Fintecture\Payment\Controller\Standard;

use Fintecture\Payment\Controller\WebhookAbstract;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;

class PaymentCreated extends WebhookAbstract
{
    public function execute()
    {
        $result = $this->resultRawFactory->create();
        $result->setHeader('Content-Type', 'text/plain');

        if (!$this->validateWebhook()) {
            $this->fintectureLogger->error('Webhook', [
                'message' => 'Invalid signature',
            ]);
            $result->setHttpResponseCode(401);
            $result->setContents('invalid_signature');

            return $result;
        }

        $params = [
            'type' => $this->request->getParam('type', ''),
            'state' => $this->request->getParam('state', ''),
            'status' => $this->request->getParam('status', ''),
            'transferState' => $this->request->getParam('transfer_state', ''),
            'amount' => $this->request->getParam('amount', ''),
            'receivedAmount' => $this->request->getParam('received_amount', ''),
            'lastTransactionAmount' => $this->request->getParam('last_transaction_amount', ''),
            'sessionId' => $this->request->getParam('session_id', ''),
            'refundedSessionId' => $this->request->getParam('refunded_session_id', ''),
        ];

        if (!in_array($params['type'], self::ALLOWED_WEBHOOK_TYPES)) {
            $result->setContents('invalid_webhook_type');

            return $result;
        }

        $isRefund = !empty($params['refundedSessionId']);

        $sessionId = $isRefund ? $params['refundedSessionId'] : $params['sessionId'];

        $order = $this->fintectureHelper->getOrderBySessionId($sessionId);
        if (!$order) {
            $this->fintectureLogger->error('Webhook', [
                'message' => 'No order found',
                'state' => $params['state'],
                'status' => $params['status'],
                'sessionId' => $sessionId,
                'isRefund' => $isRefund ? 'true' : 'false',
            ]);
            $result->setContents('invalid_order');

            return $result;
        }

        try {
            if ($isRefund) {
                if ((float) $order->getData('fintecture_payment_refund_amount') === (float) $order->getBaseGrandTotal()) {
                    $result->setContents('order_already_refunded');

                    return $result;
                }

                $decodedState = base64_decode($params['state']);
                if ($decodedState) {
                    $decodedStateObj = json_decode($decodedState);
                    if ($decodedStateObj) {
                        if (property_exists($decodedState, 'creditmemo_transaction_id')) {
                            // Use existing credit memo
                            return $this->refund($order, $params['status'], (float) $params['amount'], $decodedStateObj->creditmemo_transaction_id);
                        }
                    }
                }

                return $this->refund($order, $params['status'], (float) $params['amount']);
            }

            return $this->payment($order, $params);
        } catch (LocalizedException $e) {
            $this->fintectureLogger->error('Webhook', ['exception' => $e]);
            $result->setHttpResponseCode(500);
            $result->setContents('unknown_error');
        } catch (\Exception $e) {
            $this->fintectureLogger->error('Webhook', ['exception' => $e]);
            $result->setHttpResponseCode(500);
            $result->setContents('unknown_error');
        }

        return $result;
    }

    private function payment(Order $order, array $params): Raw
    {
        $result = $this->resultRawFactory->create();
        $result->setHeader('Content-Type', 'text/plain');

        $statuses = $this->fintectureHelper->getOrderStatus($params);
        if (!$statuses) {
            $this->fintectureLogger->debug('Webhook', [
                'orderIncrementId' => $order->getIncrementId(),
                'fintectureStatus' => $params['status'],
                'status' => 'Unhandled status',
            ]);

            $result->setHttpResponseCode(400);
            $result->setContents('invalid_status');

            return $result;
        }

        $this->fintectureLogger->debug('Webhook', [
            'orderIncrementId' => $order->getIncrementId(),
            'fintectureStatus' => $params['status'],
            'status' => $statuses['status'],
        ]);

        if ($params['type'] === 'ManualTransfer' && (
            ($params['status'] === 'payment_partial' && $params['transferState'] === 'insufficient')
            || ($params['status'] === 'payment_created' && $params['transferState'] === 'overpaid')
            || ($params['status'] === 'payment_created' && $params['transferState'] === 'received')
        )) {
            $this->handlePayment->create($order, $params, $statuses, true, true);

            return $result;
        } elseif ($order->getStatus() === $statuses['status']) {
            // Check if the state to set is the same as the current one
            // If yes don't re-set it
            $this->fintectureLogger->info('Webhook', [
                'message' => 'Status is already set',
                'orderIncrementId' => $order->getIncrementId(),
                'currentStatus' => $order->getStatus(),
                'status' => $statuses['status'],
            ]);

            $result->setContents('status_already_set');
        } elseif ($this->fintectureHelper->isStatusAlreadyFinal($order)) {
            // Check if the order has already been in the final status
            // If yes don't re-set it
            $this->fintectureLogger->info('Webhook', [
                'message' => 'Status is already final',
                'orderIncrementId' => $order->getIncrementId(),
                'currentStatus' => $order->getStatus(),
                'status' => $statuses['status'],
            ]);

            $result->setContents('status_already_final');
        } elseif ($this->fintectureHelper->isStatusInHistory($order, $statuses['status'])) {
            // Check if the order has already been in this state
            // If yes don't re-set it
            $this->fintectureLogger->info('Webhook', [
                'message' => 'Status is already in history',
                'orderIncrementId' => $order->getIncrementId(),
                'currentStatus' => $order->getStatus(),
                'status' => $statuses['status'],
            ]);
            $result->setContents('status_already_in_history');
        } elseif (in_array($statuses['status'], [
            $this->config->getPaymentCreatedStatus(),
            $this->config->getPaymentPendingStatus(),
        ])) {
            if ($statuses['status'] === $this->config->getPaymentCreatedStatus()) {
                $this->handlePayment->create($order, $params, $statuses, true);
            } else {
                $this->handlePayment->changeOrderState($order, $params, $statuses, true);
            }
        } else {
            $this->handlePayment->fail($order, $params, $statuses, true);
        }

        return $result;
    }

    private function refund(Order $order, string $status, float $amount, ?string $creditmemoTransactionId = null): Raw
    {
        $result = $this->resultRawFactory->create();
        $result->setHeader('Content-Type', 'text/plain');

        if ($status === 'payment_created') {
            if (!is_null($creditmemoTransactionId)) {
                $creditmemo = $this->fintectureHelper->getCreditmemoByTransactionId($order, $creditmemoTransactionId);

                $appliedRefund = $creditmemo ? $this->handleRefund->apply($order, $creditmemo, $amount) : false;
            } else {
                $appliedRefund = $this->handleRefund->applyWithoutCreditmemo($order, $amount);
            }

            if (!$appliedRefund) {
                $result->setHttpResponseCode(400);
                $result->setContents('refund_not_applied');
            }
        } else {
            $result->setContents('invalid_refund_status');
        }

        return $result;
    }
}
