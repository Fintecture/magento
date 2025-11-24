<?php

namespace Fintecture\Payment\Gateway\Config;

use Magento\Payment\Gateway\Config\Config as BaseConfig;

class BnplConfig extends BaseConfig
{
    public const CODE = 'fintecture_bnpl';

    public const KEY_ACTIVE = 'active';
    public const KEY_RECOMMEND_BNPL_BADGE = 'recommend_bnpl_badge';

    public function isActive(): bool
    {
        return (bool) $this->getValue(self::KEY_ACTIVE);
    }

    public function isRecommendedBnplBadgeActive(): bool
    {
        return (bool) $this->getValue(self::KEY_RECOMMEND_BNPL_BADGE);
    }
}
