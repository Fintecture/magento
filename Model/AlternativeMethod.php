<?php

declare(strict_types=1);

namespace Fintecture\Payment\Model;

use Magento\Framework\Data\OptionSourceInterface;

class AlternativeMethod implements OptionSourceInterface
{
    public const ALTERNATIVE_METHOD_QRCODE = 'qrcode';
    public const ALTERNATIVE_METHOD_SEND = 'send';

    public function toOptionArray(): array
    {
        return [
            [
                'value' => static::ALTERNATIVE_METHOD_QRCODE,
                'label' => __('Payment by QR Code'),
            ],
            [
                'value' => static::ALTERNATIVE_METHOD_SEND,
                'label' => __('Payment by SMS or Email'),
            ],
        ];
    }
}
