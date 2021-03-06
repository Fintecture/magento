<?php

declare(strict_types=1);

namespace Fintecture\Payment\Controller\Standard;

use Exception;
use Fintecture\Payment\Controller\FintectureAbstract;
use Magento\Framework\Exception\LocalizedException;

class Redirect extends FintectureAbstract
{
    public function execute()
    {
        if (!$this->request->isAjax()) {
            $this->fintectureLogger->error('Redirection error', ['message' => 'non ajax request']);
            throw new LocalizedException(__('Redirection error: non ajax request'));
        }

        try {
            $quoteId = $this->request->getParam('quoteId');
            if (strlen($quoteId) === 32) {
                try {
                    $quoteId = $this->maskedQuoteIdToQuoteId->execute($quoteId);
                } catch (Exception $e) {
                    $this->fintectureLogger->error('Redirection error', [
                        'exception' => $e,
                        'message' => "Can't find a quote with this masked id",
                        'maskedQuoteId' => $quoteId
                    ]);
                }
            }

            $quote = $this->quoteRepository->get($quoteId);
            if (!$quote) {
                $message = "Can't find a quote with this id";
                $this->fintectureLogger->error('Redirection error', [
                    'message' => $message,
                    'quoteId' => $quoteId
                ]);
                throw new LocalizedException(__($message));
            }

            $redirectUrl = $this->paymentMethod->getPaymentGatewayRedirectUrl();
            $this->fintectureLogger->debug('Redirection', [
                'quoteId' => $quote->getId(),
                'reservedIncrementOrderId' => $quote->getReservedOrderId(), // it's the incrementId, not the orderId
                'redirectUrl' => $redirectUrl
            ]);

            return $this->resultJsonFactory->create()->setData([
                'url' => $redirectUrl
            ]);
        } catch (Exception $e) {
            $this->fintectureLogger->error('Redirection error', ['exception' => $e]);
            throw new LocalizedException(__($e->getMessage()));
        }
    }
}
