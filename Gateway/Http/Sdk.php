<?php

declare(strict_types=1);

namespace Fintecture\Payment\Gateway\Http;

use Fintecture\Payment\Gateway\Config\Config;
use Fintecture\Payment\Logger\Logger;
use Fintecture\Payment\Model\Cache\OperateCustomCache;
use Fintecture\PisClient;
use Magento\Framework\Encryption\EncryptorInterface;
use Symfony\Component\HttpClient\Psr18Client;

class Sdk
{
    /** @var Config */
    protected $config;

    /** @var Logger */
    protected $fintectureLogger;

    /** @var PisClient */
    public $pisClient;

    /** @var EncryptorInterface */
    protected $encryptor;

    /** @var OperateCustomCache */
    protected $operateCustomCache;

    public function __construct(
        Config $config,
        Logger $fintectureLogger,
        EncryptorInterface $encryptor,
        OperateCustomCache $operateCustomCache
    ) {
        $this->config = $config;
        $this->fintectureLogger = $fintectureLogger;
        $this->encryptor = $encryptor;
        $this->operateCustomCache = $operateCustomCache;

        if ($this->validateConfigValue()) {
            try {
                $privateKey = null;
                $encryptedPrivateKey = $this->config->getAppPrivateKey();
                if ($encryptedPrivateKey) {
                    $privateKey = $this->encryptor->decrypt($encryptedPrivateKey);
                }

                $this->pisClient = new PisClient([
                    'appId' => $this->config->getAppId(),
                    'appSecret' => $this->config->getAppSecret(),
                    'privateKey' => $privateKey,
                    'environment' => $this->config->getAppEnvironment(),
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

    public function validateConfigValue(): bool
    {
        if (!$this->config->getAppEnvironment()
            || !$this->config->getAppPrivateKey()
            || !$this->config->getAppId()
            || !$this->config->getAppSecret()
        ) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string>|false
     */
    public function getPaymentMethods()
    {
        if (!$this->isPisClientInstantiated()) {
            $this->fintectureLogger->warning('SDK not initialized; skip payment methods');

            return false;
        }

        $hasPaymentMethodsInCache = $this->operateCustomCache->get('payment_methods');
        if (!is_null($hasPaymentMethodsInCache)) {
            return $hasPaymentMethodsInCache;
        }

        try {
            $pisToken = $this->pisClient->token->generate();
            if (!$pisToken->error) {
                $this->pisClient->setAccessToken($pisToken); // set token of PIS client
            } else {
                throw new \Exception($pisToken->errorMsg);
            }

            $apiResponse = $this->pisClient->application->get(['with_payment_methods' => true]);
            if (!$apiResponse->error) {
                $paymentMethods = [];
                if (isset($apiResponse->result->data->attributes->payment_methods)) {
                    foreach ($apiResponse->result->data->attributes->payment_methods as $paymentMethod) {
                        $paymentMethods[] = $paymentMethod->id;
                    }

                    $this->operateCustomCache->save('payment_methods', $paymentMethods, 600);

                    return $paymentMethods;
                }
            }

            return false;
        } catch (\Exception $e) {
            $this->fintectureLogger->error('getPaymentMethods exception', ['exception' => $e]);

            return false;
        }
    }
}
