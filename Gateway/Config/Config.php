<?php

namespace Fintecture\Payment\Gateway\Config;

use Magento\Payment\Gateway\Config\Config as BaseConfig;

class Config extends BaseConfig
{
    const CODE = 'fintecture';
    const VERSION = '3.5.5';

    const KEY_SHOP_NAME = 'general/store_information/name';
    const KEY_ACTIVE = 'active';
    const KEY_ALLOW_SPECIFIC = 'allowspecific';
    const KEY_SPECIFIC_COUNTRY = 'specificcountry';
    const KEY_ENVIRONMENT = 'environment';
    const KEY_APP_ID_SANDBOX = 'fintecture_app_id_sandbox';
    const KEY_APP_ID_PRODUCTION = 'fintecture_app_id_production';
    const KEY_APP_SECRET_SANDBOX = 'fintecture_app_secret_sandbox';
    const KEY_APP_SECRET_PRODUCTION = 'fintecture_app_secret_production';
    const KEY_PRIVATE_KEY_SANDBOX = 'custom_file_upload_sandbox';
    const KEY_PRIVATE_KEY_PRODUCTION = 'custom_file_upload_production';
    const KEY_REFUND_STATUSES_ACTIVE = 'refund_statuses_active';
    const KEY_EXPIRATION_ACTIVE = 'expiration_active';
    const KEY_EXPIRATION_AFTER = 'expiration_after';
    const KEY_INVOICING_ACTIVE = 'invoicing_active';
    const KEY_ALTERNATIVE_METHOD_ACTIVE = 'alternative_method_active';
    const KEY_ALTERNATIVE_METHOD = 'alternative_method';
    const KEY_CHECKOUT_DESIGN_SELECTION = 'checkout_design_selection';
    const KEY_CUSTOM_RECONCILIATION_FIELD_ACTIVE = 'custom_reconciliation_field_active';
    const KEY_CUSTOM_RECONCILIATION_FIELD = 'custom_reconciliation_field';
    const KEY_RECOMMEND_IT_BADGE = 'recommend_it_badge';
    const KEY_FIRST_POSITION_ACTIVE = 'first_position_active';
    const KEY_FIRST_POSITION_AMOUNT = 'first_position_amount';

    public function getShopName(): ?string
    {
        return $this->getValue(self::KEY_SHOP_NAME);
    }

    public function allowSpecific(): bool
    {
        return (bool) $this->getValue(self::KEY_ACTIVE);
    }

    public function getSpecificCountries(): ?array
    {
        $specificCountries = $this->getValue(self::KEY_SPECIFIC_COUNTRY);
        if ($specificCountries) {
            return explode(',', $specificCountries);
        }

        return null;
    }

    public function isActive(): bool
    {
        return (bool) $this->getValue(self::KEY_ACTIVE);
    }

    public function getAppEnvironment(): ?string
    {
        return $this->getValue(self::KEY_ENVIRONMENT);
    }

    public function getAppId(?string $environment = null, ?int $storeId = null): ?string
    {
        $environment = $environment ?: $this->getAppEnvironment();
        if ($environment) {
            if ($environment === 'sandbox') {
                return $this->getValue(self::KEY_APP_ID_SANDBOX, $storeId);
            } elseif ($environment === 'production') {
                return $this->getValue(self::KEY_APP_ID_PRODUCTION, $storeId);
            }
        }

        return null;
    }

    public function getAppSecret(?string $environment = null, ?int $storeId = null): ?string
    {
        $environment = $environment ?: $this->getAppEnvironment();
        if ($environment) {
            if ($environment === 'sandbox') {
                return $this->getValue(self::KEY_APP_SECRET_SANDBOX, $storeId);
            } elseif ($environment === 'production') {
                return $this->getValue(self::KEY_APP_SECRET_PRODUCTION, $storeId);
            }
        }

        return null;
    }

    public function getAppPrivateKey(?string $environment = null, ?int $storeId = null): ?string
    {
        $environment = $environment ?: $this->getAppEnvironment();
        if ($environment) {
            if ($environment === 'sandbox') {
                return $this->getValue(self::KEY_PRIVATE_KEY_SANDBOX, $storeId);
            } elseif ($environment === 'production') {
                return $this->getValue(self::KEY_PRIVATE_KEY_PRODUCTION, $storeId);
            }
        }

        return null;
    }

    public function isRefundStatusesActive(): bool
    {
        return (bool) $this->getValue(self::KEY_REFUND_STATUSES_ACTIVE);
    }

    public function isExpirationActive(): bool
    {
        return (bool) $this->getValue(self::KEY_EXPIRATION_ACTIVE);
    }

    public function getExpirationAfter(): ?int
    {
        return $this->getValue(self::KEY_EXPIRATION_AFTER);
    }

    public function isInvoicingActive(): bool
    {
        return (bool) $this->getValue(self::KEY_INVOICING_ACTIVE);
    }

    public function isAlternativeMethodActive(): bool
    {
        return (bool) $this->getValue(self::KEY_ALTERNATIVE_METHOD_ACTIVE);
    }

    public function getAlternativeMethod(): ?string
    {
        return $this->getValue(self::KEY_ALTERNATIVE_METHOD);
    }

    public function getCheckoutDesign(): string
    {
        return $this->getValue(self::KEY_CHECKOUT_DESIGN_SELECTION);
    }

    public function isCustomReconciliationFieldActive(): bool
    {
        return (bool) $this->getValue(self::KEY_CUSTOM_RECONCILIATION_FIELD_ACTIVE);
    }

    public function getCustomReconciliationField(): ?string
    {
        return $this->getValue(self::KEY_CUSTOM_RECONCILIATION_FIELD);
    }

    public function isRecommendedItBadgeActive(): bool
    {
        return (bool) $this->getValue(self::KEY_RECOMMEND_IT_BADGE);
    }

    public function isFirstPositionActive(): bool
    {
        return (bool) $this->getValue(self::KEY_FIRST_POSITION_ACTIVE);
    }

    public function getFirstPositionAmount(): float
    {
        return (float) $this->getValue(self::KEY_FIRST_POSITION_AMOUNT);
    }

    public function getNewOrderStatus(): string
    {
        $status = $this->getValue('new_order_status');
        if (!$status) {
            $status = 'pending';
        }

        return $status;
    }

    public function getPaymentCreatedStatus(): string
    {
        $status = $this->getValue('payment_created_status');
        if (!$status) {
            $status = 'processing';
        }

        return $status;
    }

    public function getOrderCreatedStatus(): string
    {
        $status = $this->getValue('order_created_status');
        if (!$status) {
            $status = 'fintecture_order_created';
        }

        return $status;
    }

    public function getPaymentPendingStatus(): string
    {
        $status = $this->getValue('payment_pending_status');
        if (!$status) {
            $status = 'pending_payment';
        }

        return $status;
    }

    public function getPaymentOverpaidStatus(): string
    {
        $status = $this->getValue('payment_overpaid_status');
        if (!$status) {
            $status = 'processing';
        }

        return $status;
    }

    public function getPaymentPartialStatus(): string
    {
        $status = $this->getValue('payment_partial_status');
        if (!$status) {
            $status = 'pending_payment';
        }

        return $status;
    }

    public function getPaymentFailedStatus(): string
    {
        $status = $this->getValue('payment_failed_status');
        if (!$status) {
            $status = 'canceled';
        }

        return $status;
    }

    public function getPartialRefundStatus(): string
    {
        $status = $this->getValue('partial_refund_status');
        if (!$status) {
            $status = 'fintecture_partial_refund';
        }

        return $status;
    }
}
