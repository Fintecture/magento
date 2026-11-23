<?php

declare(strict_types=1);

namespace Fintecture\Payment\Controller\Standard;

use chillerlan\QRCode\QRCode as QRCodeGenerator;
use Fintecture\Payment\Controller\FintectureAbstract;
use Fintecture\Payment\Helper\Fintecture;
use Magento\Framework\View\Element\Template;

class Send extends FintectureAbstract
{
    public function execute()
    {
        if (!$this->sdk->isPisClientInstantiated()) {
            throw new \Exception('PISClient not instantiated');
        }

        $step = (int) $this->request->getParam('step');
        $method = (string) $this->request->getParam('method');

        $qrCode = '';
        $reference = '';
        $amount = '';
        $currency = '';
        $sessionId = '';
        if ($step === 2) {
            // Call API RTP with method
            $order = $this->getOrder();
            if (!$order) {
                $this->fintectureLogger->error('Send', ['message' => 'No order found']);
                throw new \Exception('Send error: no order found');
            }

            $data = $this->fintectureHelper->generatePayload($order, Fintecture::RTP_TYPE, $method);
            $apiResponse = $this->requestToPay->get($order, $data);

            $reference = $data['data']['attributes']['communication'];
            $amount = $data['data']['attributes']['amount'];
            $currency = $data['data']['attributes']['currency'];
            $sessionId = $apiResponse->meta->session_id ?? '';
            $url = $apiResponse->meta->url ?? '';

            // chillerlan/php-qrcode is an optional dependency
            if (class_exists(QRCodeGenerator::class)) {
                if (!empty($url)) {
                    $qrCode = (new QRCodeGenerator())->render($url);
                } else {
                    $this->fintectureLogger->error('QR Code', ['message' => 'URL is empty']);
                }
            } else {
                $this->fintectureLogger->error('QR Code', ['message' => 'chillerlan/php-qrcode dependency is not installed']);
            }
        }

        $sendUrl = $this->fintectureHelper->getSendUrl() . '?step=2&method=%s';

        $sendByEmailUrl = sprintf($sendUrl, 'email');
        $sendBySMSUrl = sprintf($sendUrl, 'sms');

        $page = $this->pageFactory->create();

        /** @var Template $block */
        $block = $page->getLayout()->getBlock('fintecture_standard_send');
        $block->setData('step', $step);
        $block->setData('sendByEmailUrl', $sendByEmailUrl);
        $block->setData('sendBySMSUrl', $sendBySMSUrl);
        $block->setData('qrCode', $qrCode);
        $block->setData('reference', $reference);
        $block->setData('amount', $amount);
        $block->setData('currency', $currency);
        $block->setData('sessionId', $sessionId);

        return $page;
    }
}
