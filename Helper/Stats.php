<?php

declare(strict_types=1);

namespace Fintecture\Payment\Helper;

use Fintecture\Payment\Gateway\Config\Config;
use Fintecture\Payment\Logger\Logger as FintectureLogger;
use Fintecture\Payment\Model\Environment;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Payment\Model\Config as PaymentConfig;
use Magento\Store\Model\StoreManagerInterface;

class Stats
{
    /** @var Config */
    protected $config;

    /** @var FintectureLogger */
    protected $fintectureLogger;

    /** @var ResourceConnection */
    protected $resourceConnection;

    /** @var ProductMetadataInterface */
    protected $productMetadata;

    /** @var PaymentConfig */
    protected $paymentConfig;

    /** @var StoreManagerInterface */
    protected $storeManager;

    public function __construct(
        Config $config,
        FintectureLogger $fintectureLogger,
        ResourceConnection $resourceConnection,
        ProductMetadataInterface $productMetadata,
        PaymentConfig $paymentConfig,
        StoreManagerInterface $storeManager
    ) {
        $this->config = $config;
        $this->fintectureLogger = $fintectureLogger;
        $this->resourceConnection = $resourceConnection;
        $this->productMetadata = $productMetadata;
        $this->paymentConfig = $paymentConfig;
        $this->storeManager = $storeManager;
    }

    public function getMySQLVersion(): string
    {
        $version = '';

        try {
            $connection = $this->resourceConnection->getConnection();
            $query = 'SELECT VERSION()';
            $version = $connection->fetchOne($query);
        } catch (\Exception $e) {
            $this->fintectureLogger->debug("Can't detect MySQL version.");
        }

        return $version;
    }

    public function getMagentoVersion(): string
    {
        $version = $this->productMetadata->getVersion();
        if ($version === 'UNKNOWN') {
            $this->fintectureLogger->debug("Can't detect Magento version.");

            return 'UNKNOWN';
        }

        return $version;
    }

    public function getNumberOfActivePaymentMethods(): int
    {
        return count($this->paymentConfig->getActiveMethods());
    }

    public function getConfigurationSummary(): array
    {
        return [
            'type' => 'php-mg-1',
            'php_version' => PHP_VERSION,
            'mysql_version' => $this->getMySQLVersion(),
            'shop_name' => $this->config->getShopName(),
            'shop_domain' => $this->storeManager->getStore()->getBaseUrl(),
            'shop_cms' => 'magento',
            'shop_cms_version' => $this->getMagentoVersion(),
            'module_version' => Config::VERSION,
            'module_position' => '', // TODO: find way to get to find position
            'shop_payment_methods' => $this->getNumberOfActivePaymentMethods(),
            'module_enabled' => $this->config->isActive(),
            'module_production' => $this->config->getAppEnvironment() === Environment::ENVIRONMENT_PRODUCTION ? 1 : 0,
            'module_sandbox_app_id' => $this->config->getAppId(Environment::ENVIRONMENT_SANDBOX),
            'module_production_app_id' => $this->config->getAppId(Environment::ENVIRONMENT_PRODUCTION),
            'module_checkout_design' => $this->config->getCheckoutDesign(),
            'module_recommended_it' => $this->config->isRecommendedItBadgeActive(),
            'module_force_position' => $this->config->isFirstPositionActive(),
            'module_force_position_min_amount' => $this->config->getFirstPositionAmount(),
        ];
    }
}
