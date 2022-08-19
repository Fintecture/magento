<?php

declare(strict_types=1);

namespace Fintecture\Payment\Model;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Payment\Helper\Data;

class ConfigProvider implements ConfigProviderInterface
{
    /** @var string */
    protected $methodCode = Fintecture::CODE;

    /** @var Fintecture */
    protected $method;

    public function __construct(Data $paymenthelper)
    {
        $this->method = $paymenthelper->getMethodInstance($this->methodCode);
    }

    public function getConfig()
    {
        /** @phpstan-ignore-next-line : we will refactor the plugin without AbstractMethod */
        return $this->method->isAvailable() ? [
            'payment' => [
                'fintecture' => [
                    'redirectUrl' => $this->method->getRedirectUrl()
                ]
            ]
        ] : [];
    }
}
