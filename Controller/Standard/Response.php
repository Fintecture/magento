<?php

declare(strict_types=1);

namespace Fintecture\Payment\Controller\Standard;

use Fintecture\Payment\Controller\FintectureAbstract;
use Magento\Framework\Exception\LocalizedException;

class Response extends FintectureAbstract
{
    public function execute()
    {
        try {
            if (!$this->sdk->isPisClientInstantiated()) {
                throw new \Exception('PISClient not instantiated');
            }

            $state = $this->request->getParam('state');
            $sessionId = $this->request->getParam('session_id');
            if (!$state || !$sessionId) {
                $this->fintectureLogger->error('Response', [
                    'message' => 'Invalid params',
                ]);

                return $this->redirectToCheckoutWithError();
            }

            $decodedState = json_decode(base64_decode($state));
            if (!is_object($decodedState) || !property_exists($decodedState, 'order_id')) {
                $this->fintectureLogger->error('Response', [
                    'message' => "Can't find an order id in the state",
                ]);

                return $this->redirectToCheckoutWithError();
            }

            $orderId = $decodedState->order_id;
            $order = $this->fintectureHelper->getOrderByIncrementId($orderId);
            if (!$order) {
                $this->fintectureLogger->error('Response', [
                    'message' => "Can't find an order associated with this state",
                    'orderId' => $orderId,
                ]);

                return $this->redirectToCheckoutWithError();
            }

            if ($order->getExtOrderId() !== $sessionId) {
                $this->fintectureLogger->error('Response', [
                    'message' => 'Session does not belong to the order',
                    'orderIncrementId' => $order->getIncrementId(),
                    'sessionId' => $sessionId,
                ]);

                return $this->redirectToCheckoutWithError();
            }

            $pisToken = $this->sdk->pisClient->token->generate();
            if (!$pisToken->error) {
                $this->sdk->pisClient->setAccessToken($pisToken); // set token of PIS client
            } else {
                throw new \Exception($pisToken->errorMsg);
            }

            $apiResponse = $this->sdk->pisClient->payment->get($sessionId);
            if (!$apiResponse->error) {
                $params = [
                    'status' => $apiResponse->meta->status ?? '',
                    'sessionId' => $sessionId,
                    'transferState' => $apiResponse->data->transfer_state ?? '',
                    'type' => $apiResponse->meta->type ?? '',
                ];

                $statuses = $this->fintectureHelper->getOrderStatus($params);

                $this->fintectureLogger->debug('Response', [
                    'orderIncrementId' => $order->getIncrementId(),
                    'fintectureStatus' => $params['status'],
                    'status' => $statuses['status'] ?? 'Unhandled status',
                ]);

                if ($statuses && in_array($statuses['status'], [
                    $this->config->getPaymentCreatedStatus(),
                    $this->config->getPaymentPendingStatus(),
                ])) {
                    if ($statuses['status'] === $this->config->getPaymentCreatedStatus()) {
                        $this->handlePayment->create($order, $params, $statuses);
                    } else {
                        $this->handlePayment->changeOrderState($order, $params, $statuses);
                    }

                    try {
                        $this->checkoutSession->setLastQuoteId($order->getQuoteId());
                        $this->checkoutSession->setLastSuccessQuoteId($order->getQuoteId());
                        $this->checkoutSession->setLastOrderId($order->getId());
                        $this->checkoutSession->setLastRealOrderId($order->getIncrementId());
                        $this->checkoutSession->setLastOrderStatus($order->getStatus());

                        return $this->resultRedirect->create()->setPath(
                            $this->fintectureHelper->getUrl('checkout/onepage/success?status=' . $params['status'])
                        );
                    } catch (\Exception $e) {
                        $this->fintectureLogger->error('Response', [
                            'exception' => $e,
                            'orderIncrementId' => $order->getIncrementId(),
                            'status' => $order->getStatus(),
                        ]);
                    }
                } else {
                    $this->handlePayment->fail($order, $params, $statuses);

                    return $this->redirectToCheckoutWithError($params['status']);
                }
            } else {
                $this->fintectureLogger->error('Response', [
                    'message' => 'Invalid payment API response',
                    'response' => $apiResponse->errorMsg,
                ]);
            }
        } catch (LocalizedException $e) {
            $this->fintectureLogger->error('Response', ['exception' => $e]);
        } catch (\Exception $e) {
            $this->fintectureLogger->error('Response', ['exception' => $e]);
        }

        return $this->redirectToCheckoutWithError();
    }
}
