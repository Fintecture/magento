<?php

/**
 *  2020 Fintecture SAS
 *
 *  @author Fintecture
 *  @copyright 2020 Fintecture SAS
 *  @license http://www.gnu.org/licenses/gpl-3.0.txt General Public License v3.0 (GPLv3)
 */

namespace Fintecture\Tests\Controller\Standard;

use Fintecture\Api\ApiResponse;
use Fintecture\Payment\Controller\Standard\Send;
use Fintecture\Payment\Gateway\Http\Sdk;
use Fintecture\Payment\Gateway\RequestToPay;
use Fintecture\Payment\Helper\Fintecture as FintectureHelper;
use Fintecture\Tests\Controller\ControllerTestCase;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Request\Http;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\LayoutInterface;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use Magento\Sales\Model\Order;
use Psr\Http\Message\ResponseInterface;

class SendTest extends ControllerTestCase
{
    private const CHECKOUT_ORDER_ID = '000000001';
    private const OTHER_ORDER_ID = '000000999';

    public function testPaymentRequestIsCreatedForTheCheckoutOrderOnly(): void
    {
        $checkoutOrder = $this->createStub(Order::class);
        $otherOrder = $this->createStub(Order::class);

        $checkoutSession = $this->createStub(CheckoutSession::class);
        $checkoutSession->method('__call')->willReturnCallback(static function (string $method) {
            return $method === 'getLastRealOrderId' ? self::CHECKOUT_ORDER_ID : null;
        });

        $helper = $this->createStub(FintectureHelper::class);
        $helper->method('getOrderByIncrementId')->willReturnMap([
            [self::CHECKOUT_ORDER_ID, $checkoutOrder],
            [self::OTHER_ORDER_ID, $otherOrder],
        ]);
        $helper->method('generatePayload')->willReturn([
            'data' => ['attributes' => ['communication' => 'REF', 'amount' => '1.00', 'currency' => 'EUR']],
        ]);

        $requestToPay = $this->createMock(RequestToPay::class);
        $requestToPay->expects($this->once())
            ->method('get')
            ->with($this->identicalTo($checkoutOrder))
            ->willReturn(new ApiResponse(
                $this->createStub(ResponseInterface::class),
                (object) ['meta' => (object) ['session_id' => 'session', 'url' => '']]
            ));

        $sdk = $this->createStub(Sdk::class);
        $sdk->method('isPisClientInstantiated')->willReturn(true);

        $layout = $this->createStub(LayoutInterface::class);
        $layout->method('getBlock')->willReturn($this->createStub(Template::class));
        $page = $this->createStub(Page::class);
        $page->method('getLayout')->willReturn($layout);
        $pageFactory = $this->createStub(PageFactory::class);
        $pageFactory->method('create')->willReturn($page);

        $this->newController(Send::class, [
            Http::class => $this->requestWith(['step' => '2', 'method' => 'email', 'orderId' => self::OTHER_ORDER_ID]),
            CheckoutSession::class => $checkoutSession,
            FintectureHelper::class => $helper,
            RequestToPay::class => $requestToPay,
            Sdk::class => $sdk,
            PageFactory::class => $pageFactory,
        ])->execute();
    }

    public function testNoPaymentRequestWithoutCheckoutOrder(): void
    {
        $checkoutSession = $this->createStub(CheckoutSession::class);
        $checkoutSession->method('__call')->willReturn(null);

        $requestToPay = $this->createMock(RequestToPay::class);
        $requestToPay->expects($this->never())->method('get');

        $sdk = $this->createStub(Sdk::class);
        $sdk->method('isPisClientInstantiated')->willReturn(true);

        $this->expectExceptionMessage('Send error: no order found');

        $this->newController(Send::class, [
            Http::class => $this->requestWith(['step' => '2', 'method' => 'sms', 'orderId' => self::OTHER_ORDER_ID]),
            CheckoutSession::class => $checkoutSession,
            RequestToPay::class => $requestToPay,
            Sdk::class => $sdk,
        ])->execute();
    }
}
