<?php

/**
 *  2020 Fintecture SAS
 *
 *  @author Fintecture
 *  @copyright 2020 Fintecture SAS
 *  @license http://www.gnu.org/licenses/gpl-3.0.txt General Public License v3.0 (GPLv3)
 */

namespace Fintecture\Tests\Controller;

use Fintecture\Payment\Controller\FintectureAbstract;
use Fintecture\Payment\Gateway\Config\Config;
use Fintecture\Payment\Gateway\Connect;
use Fintecture\Payment\Gateway\HandlePayment;
use Fintecture\Payment\Gateway\Http\Sdk;
use Fintecture\Payment\Gateway\RequestToPay;
use Fintecture\Payment\Helper\Fintecture as FintectureHelper;
use Fintecture\Payment\Logger\Logger;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Session\SessionManagerInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\MaskedQuoteIdToQuoteIdInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use PHPUnit\Framework\TestCase;

abstract class ControllerTestCase extends TestCase
{
    /**
     * @param class-string<FintectureAbstract> $controllerClass
     * @param array<class-string, object> $dependencies keyed by type; missing ones are stubs
     */
    protected function newController(string $controllerClass, array $dependencies): FintectureAbstract
    {
        $get = function (string $type) use ($dependencies): object {
            return $dependencies[$type] ?? $this->createStub($type);
        };

        return new $controllerClass(
            $get(CheckoutSession::class),
            $get(Logger::class),
            $get(FintectureHelper::class),
            $get(Http::class),
            $get(RedirectFactory::class),
            $get(ManagerInterface::class),
            $get(CartRepositoryInterface::class),
            $get(MaskedQuoteIdToQuoteIdInterface::class),
            $get(SessionManagerInterface::class),
            $get(PageFactory::class),
            $get(UrlInterface::class),
            $get(Sdk::class),
            $get(Config::class),
            $get(HandlePayment::class),
            $get(Connect::class),
            $get(RequestToPay::class),
            $get(OrderRepositoryInterface::class)
        );
    }

    /**
     * @param array<string, string> $params
     */
    protected function requestWith(array $params): Http
    {
        $request = $this->createStub(Http::class);
        $request->method('getParam')->willReturnCallback(static function (string $key) use ($params) {
            return $params[$key] ?? null;
        });

        return $request;
    }
}
