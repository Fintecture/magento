<?php

declare(strict_types=1);

namespace Fintecture\Payment\Controller\Checkout;

use Fintecture\Payment\Controller\FintectureAbstract;
use Fintecture\Payment\Helper\Fintecture;

class Bnpl extends FintectureAbstract
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

            // Connect
            $data = $this->fintectureHelper->generatePayload($order, Fintecture::BNPL_TYPE);
            $apiResponse = $this->connect->get($order, $data);
            $url = $apiResponse->meta->url ?? '';

            if ($url) {
                return $this->resultRedirect->create()->setPath($url);
            }

            throw new \Exception('No url');
        } catch (\Exception $e) {
            $this->fintectureLogger->error('Checkout BNPL', [
                'message' => 'Error building redirect URL',
                'orderIncrementId' => $order ? $order->getIncrementId() : null,
                'exception' => $e,
            ]);

            return $this->redirectToCheckoutWithError();
        }
    }
}
