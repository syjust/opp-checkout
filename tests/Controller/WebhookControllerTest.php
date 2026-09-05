<?php

namespace App\Tests\Controller;

use App\Controller\WebhookController;
use App\Entity\Membership;
use App\Entity\Purchase;
use App\Repository\MembershipRepository;
use App\Repository\PurchaseRepository;
use App\Service\StripeCheckoutService;
use App\Service\StripeWebhookService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stripe\Collection;
use Stripe\Event;
use Stripe\LineItem;
use Stripe\Price;
use Stripe\Product;
use Stripe\Service\Checkout\CheckoutServiceFactory;
use Stripe\Service\Checkout\SessionService;
use Stripe\Service\SubscriptionScheduleService;
use Stripe\Service\SubscriptionService;
use Stripe\StripeClient;
use Stripe\Subscription;
use Stripe\SubscriptionSchedule;
use Symfony\Component\HttpFoundation\Request;

class WebhookControllerTest extends TestCase
{
    private StripeClient $stripeClient;
    private SubscriptionService $subscriptionService;
    private SubscriptionScheduleService $scheduleService;
    private SessionService $sessionService;
    private MembershipRepository $membershipRepo;
    private PurchaseRepository $purchaseRepo;
    private StripeCheckoutService $checkoutService;
    private StripeWebhookService $webhookService;
    private WebhookController $controller;

    protected function setUp(): void
    {
        $this->stripeClient = $this->createMock(StripeClient::class);
        $this->subscriptionService = $this->createMock(SubscriptionService::class);
        $this->scheduleService = $this->createMock(SubscriptionScheduleService::class);
        $this->sessionService = $this->createMock(SessionService::class);
        $this->stripeClient->subscriptions = $this->subscriptionService;
        $this->stripeClient->subscriptionSchedules = $this->scheduleService;

        $checkoutFactory = $this->createMock(CheckoutServiceFactory::class);
        $checkoutFactory->sessions = $this->sessionService;
        $this->stripeClient->checkout = $checkoutFactory;

        $this->membershipRepo = $this->createMock(MembershipRepository::class);
        $this->purchaseRepo = $this->createMock(PurchaseRepository::class);
        $this->checkoutService = $this->createMock(StripeCheckoutService::class);
        $this->webhookService = $this->createMock(StripeWebhookService::class);

        $this->controller = new WebhookController(
            $this->webhookService,
            $this->checkoutService,
            $this->membershipRepo,
            $this->purchaseRepo,
            $this->stripeClient,
            new NullLogger(),
        );
    }

    public function testCreateSubscriptionScheduleOnCheckoutCompleted(): void
    {
        $session = (object) [
            'id' => 'cs_test_123',
            'subscription' => 'sub_abc',
            'customer_email' => 'alice@example.com',
            'customer' => 'cus_123',
            'metadata' => (object) [
                'school_year' => '2026-2027',
                'adhesion_amount_cents' => '0',
            ],
        ];

        $subscription = Subscription::constructFrom([
            'id' => 'sub_abc',
            'metadata' => ['opp_installments' => '10'],
            'items' => ['data' => [['price' => ['recurring' => ['interval' => 'month', 'interval_count' => 1]]]]],
            'current_period_start' => 1693526400,
        ]);
        $this->subscriptionService->method('retrieve')->willReturn($subscription);

        $schedule = SubscriptionSchedule::constructFrom(['id' => 'sub_sched_123']);
        $this->scheduleService->expects($this->once())
            ->method('create')
            ->with(['from_subscription' => 'sub_abc'])
            ->willReturn($schedule);

        $this->scheduleService->expects($this->once())
            ->method('update')
            ->willReturnCallback(function ($id, $params) {
                $this->assertSame('sub_sched_123', $id);
                $this->assertSame('cancel', $params['end_behavior']);
                $this->assertArrayHasKey('phases', $params);
                return SubscriptionSchedule::constructFrom(['id' => $id]);
            });

        $lineItems = new Collection();
        $lineItems->data = [];
        $this->sessionService->method('allLineItems')->willReturn($lineItems);

        $event = $this->buildEvent('checkout.session.completed', $session);
        $this->webhookService->method('constructEvent')->willReturn($event);

        $request = new Request([], [], [], [], [], ['HTTP_Stripe_Signature' => 'sig'], '{}');
        $response = $this->controller->handle($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testRecordsPurchasesOnCheckoutCompleted(): void
    {
        $session = (object) [
            'id' => 'cs_test_456',
            'subscription' => null,
            'customer_email' => 'bob@example.com',
            'customer' => 'cus_456',
            'metadata' => (object) [
                'school_year' => '2026-2027',
                'adhesion_amount_cents' => '1000',
            ],
        ];

        $product = Product::constructFrom([
            'id' => 'prod_cours',
            'metadata' => ['opp_category' => 'cours-annee'],
        ]);
        $price = Price::constructFrom([
            'id' => 'price_123',
            'product' => $product,
            'lookup_key' => 'cours-instruments-2026-2027-1x',
        ]);
        $lineItem = LineItem::constructFrom([
            'id' => 'li_123',
            'price' => $price,
        ]);

        $lineItems = new Collection();
        $lineItems->data = [$lineItem];
        $this->sessionService->method('allLineItems')->willReturn($lineItems);

        $this->membershipRepo->method('hasMembershipForYear')->willReturn(false);

        $savedPurchases = [];
        $this->purchaseRepo->expects($this->once())
            ->method('save')
            ->willReturnCallback(function (Purchase $purchase) use (&$savedPurchases) {
                $savedPurchases[] = $purchase;
            });

        $event = $this->buildEvent('checkout.session.completed', $session);
        $this->webhookService->method('constructEvent')->willReturn($event);

        $request = new Request([], [], [], [], [], ['HTTP_Stripe_Signature' => 'sig'], '{}');
        $response = $this->controller->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $savedPurchases);
        $this->assertSame('bob@example.com', $savedPurchases[0]->getEmail());
        $this->assertSame('cours-instruments-2026-2027-1x', $savedPurchases[0]->getLookupKey());
    }

    public function testSkipsNonCoursPurchases(): void
    {
        $session = (object) [
            'id' => 'cs_test_789',
            'subscription' => null,
            'customer_email' => 'carol@example.com',
            'customer' => 'cus_789',
            'metadata' => (object) [
                'school_year' => '2026-2027',
                'adhesion_amount_cents' => '1000',
            ],
        ];

        $adhesionProduct = Product::constructFrom([
            'id' => 'prod_adh',
            'metadata' => ['opp_category' => 'adhesion'],
        ]);
        $adhesionPrice = Price::constructFrom([
            'id' => 'price_adh',
            'product' => $adhesionProduct,
            'lookup_key' => null,
        ]);
        $lineItem = LineItem::constructFrom([
            'id' => 'li_adh',
            'price' => $adhesionPrice,
        ]);

        $lineItems = new Collection();
        $lineItems->data = [$lineItem];
        $this->sessionService->method('allLineItems')->willReturn($lineItems);

        $this->membershipRepo->method('hasMembershipForYear')->willReturn(false);

        $this->purchaseRepo->expects($this->never())->method('save');

        $event = $this->buildEvent('checkout.session.completed', $session);
        $this->webhookService->method('constructEvent')->willReturn($event);

        $request = new Request([], [], [], [], [], ['HTTP_Stripe_Signature' => 'sig'], '{}');
        $this->controller->handle($request);
    }

    private function buildEvent(string $type, object $data): Event
    {
        return Event::constructFrom([
            'id' => 'evt_test',
            'type' => $type,
            'data' => ['object' => (array) $data],
        ]);
    }
}
