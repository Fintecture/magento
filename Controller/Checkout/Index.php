<?php

declare(strict_types=1);

namespace Fintecture\Payment\Controller\Checkout;

use Fintecture\Payment\Controller\FintectureAbstract;
use Fintecture\Payment\Helper\Fintecture;
use Magento\Framework\App\ObjectManager;
use Magento\LoginAsCustomerApi\Api\GetLoggedAsCustomerAdminIdInterface;
use Magento\Sales\Model\Order;

class Index extends FintectureAbstract
{
    public function execute()
    {
        if (!$this->sdk->isPisClientInstantiated()) {
            throw new \Exception('PISClient not instantiated');
        }

        try {
            $order = $this->getOrder();
            if (!$order) {
                throw new \Exception('No order found');
            }

            $alternativeMethod = $this->getAlternativeMethod();
            if (!$alternativeMethod) {
                // Connect
                $data = $this->fintectureHelper->generatePayload($order, Fintecture::PIS_TYPE);
                $apiResponse = $this->connect->get($order, $data);
                $url = $apiResponse->meta->url ?? '';
            } else {
                // RTP
                if ($alternativeMethod === 'send') {
                    // SMS/EMAIL
                    $url = $this->getSendRedirect($order);
                } else {
                    // QR CODE
                    $url = $this->getQRCodeRedirect($order);
                }
            }

            if ($url) {
                return $this->resultRedirect->create()->setPath($url);
            }

            throw new \Exception('No url');
        } catch (\Exception $e) {
            $this->fintectureLogger->error('Checkout', [
                'message' => 'Error building redirect URL',
                'orderIncrementId' => $order ? $order->getIncrementId() : null,
                'exception' => $e,
            ]);

            return $this->redirectToCheckoutWithError();
        }
    }

    private function getQRCodeRedirect(Order $order): string
    {
        $data = $this->fintectureHelper->generatePayload($order, Fintecture::RTP_TYPE);
        $apiResponse = $this->requestToPay->get($order, $data);
        $url = $apiResponse->meta->url ?? '';

        $params = [
            'url' => urlencode($url),
            'reference' => $data['data']['attributes']['communication'],
            'amount' => $data['data']['attributes']['amount'],
            'currency' => $data['data']['attributes']['currency'],
            'session_id' => $apiResponse->meta->session_id ?? '',
            'confirm' => 0,
        ];

        return $this->fintectureHelper->getQrCodeUrl() . '?' . http_build_query($params);
    }

    private function getSendRedirect(Order $order): string
    {
        return $this->fintectureHelper->getSendUrl() . '?step=1&orderId=' . $order->getIncrementId();
    }

    /**
     * @return string|false
     */
    public function getAlternativeMethod()
    {
        $alternativeMethod = false;
        $alternativeMethodActive = false;
        if (interface_exists(GetLoggedAsCustomerAdminIdInterface::class)) {
            $getLoggedAsCustomerAdminId = ObjectManager::getInstance()->get(GetLoggedAsCustomerAdminIdInterface::class);
            if ($getLoggedAsCustomerAdminId) {
                $alternativeMethodActive = (bool) $getLoggedAsCustomerAdminId->execute() && $this->config->isAlternativeMethodActive();

                if ($alternativeMethodActive) {
                    $alternativeMethod = $this->config->getAlternativeMethod();
                    if (is_null($alternativeMethod)) {
                        $alternativeMethod = false;
                    }
                }
            }
        }

        return $alternativeMethod;
    }
}
