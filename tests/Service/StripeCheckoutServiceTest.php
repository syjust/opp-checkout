<?php

namespace App\Tests\Service;

use App\Repository\MembershipRepository;
use App\Repository\PurchaseRepository;
use App\Service\StripeCheckoutService;
use PHPUnit\Framework\TestCase;
use Stripe\Checkout\Session;
use Stripe\Collection;
use Stripe\Price;
use Stripe\Product;
use Stripe\Service\Checkout\CheckoutServiceFactory;
use Stripe\Service\Checkout\SessionService;
use Stripe\Service\PriceService;
use Stripe\Service\ProductService;
use Stripe\StripeClient;

class StripeCheckoutServiceTest extends TestCase
{
    private StripeClient $stripeClient;
    private ProductService $productService;
    private PriceService $priceService;
    private MembershipRepository $membershipRepo;
    private PurchaseRepository $purchaseRepo;
    private StripeCheckoutService $service;

    protected function setUp(): void
    {
        $this->stripeClient = $this->createMock(StripeClient::class);
        $this->productService = $this->createMock(ProductService::class);
        $this->priceService = $this->createMock(PriceService::class);
        $this->stripeClient->products = $this->productService;
        $this->stripeClient->prices = $this->priceService;

        $checkoutFactory = $this->createMock(CheckoutServiceFactory::class);
        $sessionService = $this->createMock(SessionService::class);
        $checkoutFactory->sessions = $sessionService;
        $this->stripeClient->checkout = $checkoutFactory;

        $this->membershipRepo = $this->createMock(MembershipRepository::class);
        $this->purchaseRepo = $this->createMock(PurchaseRepository::class);

        $this->service = new StripeCheckoutService(
            $this->stripeClient,
            $this->membershipRepo,
            $this->purchaseRepo,
            'pk_test_123',
        );
    }

    public function testFindProductByCategory(): void
    {
        $adhesion = Product::constructFrom([
            'id' => 'prod_adh',
            'name' => 'Adhésion',
            'metadata' => ['opp_category' => 'adhesion'],
        ]);
        $don = Product::constructFrom([
            'id' => 'prod_don',
            'name' => 'Don',
            'metadata' => ['opp_category' => 'don'],
        ]);

        $collection = new Collection();
        $collection->data = [$adhesion, $don];
        $this->productService->method('all')->willReturn($collection);

        $result = $this->service->findProductByCategory('adhesion');
        $this->assertSame('prod_adh', $result->id);

        $result2 = $this->service->findProductByCategory('don');
        $this->assertSame('prod_don', $result2->id);

        $result3 = $this->service->findProductByCategory('nonexistent');
        $this->assertNull($result3);
    }

    public function testIsEligibleForReductionWithExistingPurchase(): void
    {
        $product = Product::constructFrom([
            'id' => 'prod_guinguette',
            'name' => 'Guinguette',
            'metadata' => [
                'opp_category' => 'cours-annee',
                'opp_reduced_by' => 'cours-instruments;accordeon-aix;accordeon-aubagne',
            ],
        ]);

        $this->purchaseRepo->method('hasAnyMatchingLookupKeyPrefix')
            ->with('alice@example.com', '2026-2027', ['cours-instruments', 'accordeon-aix', 'accordeon-aubagne'])
            ->willReturn(true);

        $this->assertTrue(
            $this->service->isEligibleForReduction('alice@example.com', '2026-2027', $product)
        );
    }

    public function testIsEligibleForReductionWithoutExistingPurchase(): void
    {
        $product = Product::constructFrom([
            'id' => 'prod_guinguette',
            'name' => 'Guinguette',
            'metadata' => [
                'opp_category' => 'cours-annee',
                'opp_reduced_by' => 'cours-instruments;accordeon-aix;accordeon-aubagne',
            ],
        ]);

        $this->purchaseRepo->method('hasAnyMatchingLookupKeyPrefix')->willReturn(false);

        $this->assertFalse(
            $this->service->isEligibleForReduction('alice@example.com', '2026-2027', $product)
        );
    }

    public function testIsEligibleForReductionWithoutReducedByMetadata(): void
    {
        $product = Product::constructFrom([
            'id' => 'prod_instruments',
            'name' => 'Cours instruments',
            'metadata' => ['opp_category' => 'cours-annee'],
        ]);

        $this->assertFalse(
            $this->service->isEligibleForReduction('alice@example.com', '2026-2027', $product)
        );
    }

    public function testCreateCheckoutSessionPaymentMode(): void
    {
        $adhesion = Product::constructFrom([
            'id' => 'prod_adh',
            'name' => 'Adhésion',
            'metadata' => ['opp_category' => 'adhesion'],
        ]);
        $don = Product::constructFrom([
            'id' => 'prod_don',
            'name' => 'Don',
            'metadata' => ['opp_category' => 'don'],
        ]);
        $collection = new Collection();
        $collection->data = [$adhesion, $don];
        $this->productService->method('all')->willReturn($collection);
        $this->membershipRepo->method('hasMembershipForYear')->willReturn(false);

        $capturedParams = null;
        $this->stripeClient->checkout->sessions
            ->method('create')
            ->willReturnCallback(function ($params) use (&$capturedParams) {
                $capturedParams = $params;
                return Session::constructFrom(['id' => 'cs_test', 'url' => 'https://stripe.test/cs']);
            });

        $this->service->createCheckoutSession(
            email: 'alice@example.com',
            priceIds: ['price_cours_1x'],
            rhythm: '1x',
            adhesionAmountCents: 1000,
            donationAmountCents: 500,
            successUrl: 'https://example.com/success',
            cancelUrl: 'https://example.com/cancel',
        );

        $this->assertSame('payment', $capturedParams['mode']);
        $this->assertCount(3, $capturedParams['line_items']);
        $this->assertSame('price_cours_1x', $capturedParams['line_items'][0]['price']);
        $this->assertSame('prod_adh', $capturedParams['line_items'][1]['price_data']['product']);
        $this->assertSame(1000, $capturedParams['line_items'][1]['price_data']['unit_amount']);
        $this->assertSame('prod_don', $capturedParams['line_items'][2]['price_data']['product']);
        $this->assertSame(500, $capturedParams['line_items'][2]['price_data']['unit_amount']);
    }

    public function testCreateCheckoutSessionSubscriptionMode(): void
    {
        $collection = new Collection();
        $collection->data = [];
        $this->productService->method('all')->willReturn($collection);
        $this->membershipRepo->method('hasMembershipForYear')->willReturn(true);

        $capturedParams = null;
        $this->stripeClient->checkout->sessions
            ->method('create')
            ->willReturnCallback(function ($params) use (&$capturedParams) {
                $capturedParams = $params;
                return Session::constructFrom(['id' => 'cs_test', 'url' => 'https://stripe.test/cs']);
            });

        $this->service->createCheckoutSession(
            email: 'alice@example.com',
            priceIds: ['price_cours_10x', 'price_guinguette_10x'],
            rhythm: '10x',
            adhesionAmountCents: 0,
            donationAmountCents: 0,
            successUrl: 'https://example.com/success',
            cancelUrl: 'https://example.com/cancel',
        );

        $this->assertSame('subscription', $capturedParams['mode']);
        $this->assertCount(2, $capturedParams['line_items']);
        $this->assertArrayHasKey('subscription_data', $capturedParams);
        $this->assertSame('10', $capturedParams['subscription_data']['metadata']['opp_installments']);
    }

    public function testCreateCheckoutSessionSkipsAdhesionWhenAlreadyMember(): void
    {
        $collection = new Collection();
        $collection->data = [];
        $this->productService->method('all')->willReturn($collection);
        $this->membershipRepo->method('hasMembershipForYear')->willReturn(true);

        $capturedParams = null;
        $this->stripeClient->checkout->sessions
            ->method('create')
            ->willReturnCallback(function ($params) use (&$capturedParams) {
                $capturedParams = $params;
                return Session::constructFrom(['id' => 'cs_test', 'url' => 'https://stripe.test/cs']);
            });

        $this->service->createCheckoutSession(
            email: 'alice@example.com',
            priceIds: ['price_cours_1x'],
            rhythm: '1x',
            adhesionAmountCents: 1000,
            donationAmountCents: 0,
            successUrl: 'https://example.com/success',
            cancelUrl: 'https://example.com/cancel',
        );

        $this->assertCount(1, $capturedParams['line_items']);
    }

    public function testCreateCheckoutSessionMultipleCartItems(): void
    {
        $collection = new Collection();
        $collection->data = [];
        $this->productService->method('all')->willReturn($collection);
        $this->membershipRepo->method('hasMembershipForYear')->willReturn(true);

        $capturedParams = null;
        $this->stripeClient->checkout->sessions
            ->method('create')
            ->willReturnCallback(function ($params) use (&$capturedParams) {
                $capturedParams = $params;
                return Session::constructFrom(['id' => 'cs_test', 'url' => 'https://stripe.test/cs']);
            });

        $this->service->createCheckoutSession(
            email: 'alice@example.com',
            priceIds: ['price_a', 'price_b', 'price_c'],
            rhythm: '3x',
            adhesionAmountCents: 0,
            donationAmountCents: 0,
            successUrl: 'https://example.com/success',
            cancelUrl: 'https://example.com/cancel',
        );

        $this->assertSame('subscription', $capturedParams['mode']);
        $this->assertCount(3, $capturedParams['line_items']);
        $this->assertSame('3', $capturedParams['subscription_data']['metadata']['opp_installments']);
    }

    public function testFetchProductsByCategoryWithSeasonFilter(): void
    {
        $product = Product::constructFrom([
            'id' => 'prod_123',
            'name' => 'Cours instruments',
            'metadata' => ['opp_category' => 'cours-annee'],
        ]);

        $allProducts = new Collection();
        $allProducts->data = [$product];
        $this->productService->method('all')->willReturn($allProducts);

        $price2026 = Price::constructFrom([
            'id' => 'price_2026',
            'lookup_key' => 'cours-instruments-2026-2027-1x',
            'metadata' => ['opp_season' => '2026-2027'],
        ]);
        $price2025 = Price::constructFrom([
            'id' => 'price_2025',
            'lookup_key' => 'cours-instruments-2025-2026-1x',
            'metadata' => ['opp_season' => '2025-2026'],
        ]);

        $allPrices = new Collection();
        $allPrices->data = [$price2026, $price2025];
        $this->priceService->method('all')->willReturn($allPrices);

        $results = $this->service->fetchProductsByCategory('cours-annee', '2026-2027');
        $this->assertCount(1, $results);
        $this->assertCount(1, $results[0]['prices']);
        $this->assertSame('price_2026', $results[0]['prices'][0]->id);
    }
}
