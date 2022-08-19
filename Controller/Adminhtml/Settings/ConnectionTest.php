<?php

declare(strict_types=1);

namespace Fintecture\Payment\Controller\Adminhtml\Settings;

use Fintecture\Payment\Gateway\Client;
use Fintecture\Payment\Helper\Fintecture as FintectureHelper;
use Fintecture\Payment\Logger\Logger as FintectureLogger;
use Fintecture\Payment\Model\Environment;
use Fintecture\Payment\Model\Fintecture;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator;
use Magento\Framework\Exception\LocalizedException;

class ConnectionTest extends Action
{
    public const CONFIG_PREFIX = 'payment/fintecture/';
    protected $fintectureModel;
    protected $jsonResultFactory;
    protected $scopeConfig;
    protected $environment = Environment::ENVIRONMENT_PRODUCTION;

    /** @var FintectureHelper */
    protected $fintectureHelper;

    /** @var FintectureLogger */
    protected $fintectureLogger;

    /** @var Validator*/
    protected $formKeyValidator;

    public function __construct(
        Context $context,
        Fintecture $fintectureModel,
        JsonFactory $jsonResultFactory,
        ScopeConfigInterface $scopeConfig,
        FintectureHelper $fintectureHelper,
        FintectureLogger $fintectureLogger,
        Validator $formKeyValidator
    ) {
        parent::__construct($context);
        $this->fintectureModel = $fintectureModel;
        $this->jsonResultFactory = $jsonResultFactory;
        $this->scopeConfig = $scopeConfig;
        $this->fintectureHelper = $fintectureHelper;
        $this->fintectureLogger = $fintectureLogger;
        $this->formKeyValidator = $formKeyValidator;
    }

    public function execute()
    {
        /** @var Http $request */
        $request = $this->getRequest();
        if (!$request->isPost() || !$request->isAjax() || !$this->formKeyValidator->validate($request)) {
            throw new LocalizedException(__('Invalid request'));
        }

        $scope = $request->getParam('scope');
        $scopeId = (int) $request->getParam('scopeId');
        $fintectureConnectParameters = $request->getParams();
        $environment = $fintectureConnectParameters['fintectureEnv'] ?? Environment::ENVIRONMENT_PRODUCTION;

        $fintectureAppId = $fintectureConnectParameters['fintectureAppId'] ?? '';
        $fintectureAppSecret = $fintectureConnectParameters['fintectureAppSecret'] ?? '';
        $fintecturePrivateKey = $fintectureConnectParameters['fintecturePrivateKey'] ?? '';

        // Handle already saved app secret
        if ($fintectureAppSecret === '******') {
            $fintectureAppSecret = $this->fintectureModel->getAppSecret($environment, $scope, $scopeId);
        }

        // Handle already saved private key
        if ($fintecturePrivateKey === '') {
            $fintecturePrivateKey = $this->fintectureModel->getAppPrivateKey($environment, $scope, $scopeId);
            if (!$fintecturePrivateKey) {
                throw new LocalizedException(__('No private key file found'));
            }
        }

        $clientGateway = new Client(
            $this->fintectureHelper,
            $this->fintectureLogger,
            [
                'fintectureApiUrl' => $this->fintectureModel->getFintectureApiUrl(),
                'fintecturePrivateKey' => $fintecturePrivateKey,
                'fintectureAppId' => $fintectureAppId,
                'fintectureAppSecret' => $fintectureAppSecret,
            ]
        );

        $response = $clientGateway->testConnection();

        $resultJson = $this->jsonResultFactory->create();

        return $resultJson->setData($response);
    }
}
