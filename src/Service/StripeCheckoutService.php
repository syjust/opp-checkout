<?php

namespace App\Service;

use App\Repository\MembershipRepository;
use App\Repository\PurchaseRepository;
use Stripe\Checkout\Session;
use Stripe\Product;
use Stripe\StripeClient;

class StripeCheckoutService
{
    private const INSTALLMENTS = [
        '1x' => null,
        '3x' => 3,
        '10x' => 10,
    ];

    private ?array $productsByCategory = null;

    public function __construct(
        private readonly StripeClient $stripeClient,
        private readonly MembershipRepository $membershipRepository,
        private readonly PurchaseRepository $purchaseRepository,
        private readonly string $stripePublishableKey,
    ) {
    }

    public function getPublishableKey(): string
    {
        return $this->stripePublishableKey;
    }

    public function getCurrentSchoolYear(): string
    {
        $now = new \DateTimeImmutable();
        $year = (int) $now->format('Y');

        if ((int) $now->format('n') < 8) {
            return ($year - 1) . '-' . $year;
        }

        return $year . '-' . ($year + 1);
    }

    public function getPrice(string $priceId): ?\Stripe\Price
    {
        try {
            return $this->stripeClient->prices->retrieve($priceId);
        } catch (\Stripe\Exception\InvalidRequestException) {
            return null;
        }
    }

    public function getProduct(string $productId): Product
    {
        return $this->stripeClient->products->retrieve($productId);
    }

    public function findProductByCategory(string $category): ?Product
    {
        $this->loadProducts();

        return $this->productsByCategory[$category] ?? null;
    }

    public function isEligibleForReduction(string $email, string $schoolYear, Product $product): bool
    {
        $reducedBy = $product->metadata['opp_reduced_by'] ?? null;
        if (!$reducedBy) {
            return false;
        }

        $prefixes = explode(';', $reducedBy);

        return $this->purchaseRepository->hasAnyMatchingLookupKeyPrefix($email, $schoolYear, $prefixes);
    }

    public function fetchProductsByCategory(string $category, ?string $season = null): array
    {
        $products = [];
        $allProducts = $this->stripeClient->products->all(['active' => true, 'limit' => 100]);

        foreach ($allProducts->data as $product) {
            if (($product->metadata['opp_category'] ?? null) !== $category) {
                continue;
            }

            $prices = $this->stripeClient->prices->all([
                'product' => $product->id,
                'active' => true,
                'limit' => 100,
            ]);

            $filteredPrices = $prices->data;
            if ($season !== null) {
                $filteredPrices = array_values(array_filter(
                    $filteredPrices,
                    fn($price) => ($price->metadata['opp_season'] ?? null) === $season,
                ));
            }

            $products[] = [
                'product' => $product,
                'prices' => $filteredPrices,
            ];
        }

        return $products;
    }

    public function hasMembership(string $email): bool
    {
        return $this->membershipRepository->hasMembershipForYear(
            $email,
            $this->getCurrentSchoolYear(),
        );
    }

    public function createCheckoutSession(
        string $email,
        array $priceIds,
        string $rhythm,
        int $adhesionAmountCents,
        int $donationAmountCents,
        string $successUrl,
        string $cancelUrl,
    ): Session {
        $lineItems = [];

        foreach ($priceIds as $priceId) {
            $lineItems[] = ['price' => $priceId, 'quantity' => 1];
        }

        if ($adhesionAmountCents > 0 && !$this->hasMembership($email)) {
            $adhesionProduct = $this->findProductByCategory('adhesion');
            if ($adhesionProduct) {
                $lineItems[] = [
                    'price_data' => [
                        'currency' => 'eur',
                        'unit_amount' => $adhesionAmountCents,
                        'product' => $adhesionProduct->id,
                    ],
                    'quantity' => 1,
                ];
            }
        }

        if ($donationAmountCents > 0) {
            $donProduct = $this->findProductByCategory('don');
            if ($donProduct) {
                $lineItems[] = [
                    'price_data' => [
                        'currency' => 'eur',
                        'unit_amount' => $donationAmountCents,
                        'product' => $donProduct->id,
                    ],
                    'quantity' => 1,
                ];
            }
        }

        $params = [
            'customer_email' => $email,
            'line_items' => $lineItems,
            'success_url' => $successUrl . '?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $cancelUrl,
            'metadata' => [
                'school_year' => $this->getCurrentSchoolYear(),
                'adhesion_amount_cents' => $adhesionAmountCents,
            ],
        ];

        $installments = self::INSTALLMENTS[$rhythm] ?? null;

        if ($installments !== null) {
            $params['mode'] = 'subscription';
            $params['subscription_data'] = [
                'metadata' => ['opp_installments' => (string) $installments],
            ];
        } else {
            $params['mode'] = 'payment';
        }

        return $this->stripeClient->checkout->sessions->create($params);
    }

    private function loadProducts(): void
    {
        if ($this->productsByCategory !== null) {
            return;
        }

        $this->productsByCategory = [];
        $allProducts = $this->stripeClient->products->all(['active' => true, 'limit' => 100]);

        foreach ($allProducts->data as $product) {
            $category = $product->metadata['opp_category'] ?? null;
            if ($category !== null) {
                $this->productsByCategory[$category] = $product;
            }
        }
    }
}
