<?php

/**
 *  2020 Fintecture SAS
 *
 *  @author Fintecture
 *  @copyright 2020 Fintecture SAS
 *  @license http://www.gnu.org/licenses/gpl-3.0.txt General Public License v3.0 (GPLv3)
 */

namespace Fintecture\Tests\Controller\Standard;

use Fintecture\Payment\Controller\FintectureAbstract;
use Fintecture\Payment\Controller\Standard\Response;
use Fintecture\Payment\Gateway\Config\Config;
use Fintecture\Payment\Gateway\HandlePayment;
use Fintecture\Payment\Gateway\Http\Sdk;
use Fintecture\Payment\Helper\Fintecture as FintectureHelper;
use Fintecture\Tests\Controller\ControllerTestCase;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\MockObject\MockObject;

class ResponseTest extends ControllerTestCase
{
    private const ORDER_ID = '000000999';
    private const ORDER_SESSION_ID = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const OTHER_SESSION_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** @var HandlePayment&MockObject */
    private $handlePayment;

    protected function setUp(): void
    {
        $this->handlePayment = $this->createMock(HandlePayment::class);
    }

    public function testSessionOfAnotherOrderDoesNotMarkOrderPaid(): void
    {
        $this->handlePayment->expects($this->never())->method('create');
        $this->handlePayment->expects($this->never())->method('changeOrderState');

        $this->buildResponse(self::OTHER_SESSION_ID, 'payment_created')->execute();
    }

    public function testFailedSessionOfAnotherOrderDoesNotCancelOrder(): void
    {
        $this->handlePayment->expects($this->never())->method('fail');

        $this->buildResponse(self::OTHER_SESSION_ID, 'payment_unsuccessful')->execute();
    }

    public function testOwnSessionMarksOrderPaid(): void
    {
        $this->handlePayment->expects($this->once())->method('create');

        $this->buildResponse(self::ORDER_SESSION_ID, 'payment_created')->execute();
    }

    public function testOwnPendingSessionUpdatesOrderState(): void
    {
        $this->handlePayment->expects($this->once())->method('changeOrderState');

        $this->buildResponse(self::ORDER_SESSION_ID, 'payment_pending')->execute();
    }

    public function testOwnFailedSessionCancelsOrder(): void
    {
        $this->handlePayment->expects($this->once())->method('fail');

        $this->buildResponse(self::ORDER_SESSION_ID, 'payment_unsuccessful')->execute();
    }

    private function buildResponse(string $returnedSessionId, string $sessionStatus): FintectureAbstract
    {
        $order = $this->createStub(Order::class);
        $order->method('getId')->willReturn(999);
        $order->method('getIncrementId')->willReturn(self::ORDER_ID);
        $order->method('getExtOrderId')->willReturn(self::ORDER_SESSION_ID);

        $config = $this->createStub(Config::class);
        $config->method('getPaymentCreatedStatus')->willReturn('payment_created_status');
        $config->method('getPaymentPendingStatus')->willReturn('payment_pending_status');

        $helper = $this->createStub(FintectureHelper::class);
        $helper->method('getOrderByIncrementId')->willReturnMap([[self::ORDER_ID, $order]]);
        $helper->method('getOrderStatus')->willReturnCallback(static function (array $params): array {
            $statuses = [
                'payment_created' => ['status' => 'payment_created_status', 'state' => Order::STATE_PROCESSING],
                'payment_pending' => ['status' => 'payment_pending_status', 'state' => Order::STATE_PENDING_PAYMENT],
            ];

            return $statuses[$params['status']] ?? ['status' => 'payment_failed_status', 'state' => Order::STATE_CANCELED];
        });

        $sdk = $this->createStub(Sdk::class);
        $sdk->method('isPisClientInstantiated')->willReturn(true);
        $sdk->pisClient = $this->fakePisClient($sessionStatus);

        $redirect = $this->createStub(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $redirectFactory = $this->createStub(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($redirect);

        return $this->newController(Response::class, [
            Http::class => $this->requestWith([
                'state' => base64_encode((string) json_encode(['order_id' => self::ORDER_ID])),
                'session_id' => $returnedSessionId,
            ]),
            FintectureHelper::class => $helper,
            Config::class => $config,
            Sdk::class => $sdk,
            RedirectFactory::class => $redirectFactory,
            HandlePayment::class => $this->handlePayment,
        ]);
    }

    /**
     * Stands in for the SDK PIS client: answers every session lookup with the given status.
     */
    private function fakePisClient(string $sessionStatus): object
    {
        $apiResponse = (object) [
            'error' => false,
            'meta' => (object) ['status' => $sessionStatus, 'type' => 'PIS'],
            'data' => (object) ['transfer_state' => 'completed'],
        ];

        return new class($apiResponse) {
            public $token;
            public $payment;

            public function __construct(object $apiResponse)
            {
                $this->token = new class {
                    public function generate(): object
                    {
                        return (object) ['error' => false];
                    }
                };
                $this->payment = new class($apiResponse) {
                    private $apiResponse;

                    public function __construct(object $apiResponse)
                    {
                        $this->apiResponse = $apiResponse;
                    }

                    public function get(string $sessionId): object
                    {
                        return $this->apiResponse;
                    }
                };
            }

            public function setAccessToken($token): void
            {
            }
        };
    }
}
