<?php

namespace App\Service;

use App\Repository\MembershipRepository;
use Stripe\Checkout\Session;
use Stripe\StripeClient;

class StripeCheckoutService
{
    public function __construct(
        private readonly StripeClient $stripeClient,
        private readonly MembershipRepository $membershipRepository,
        private readonly string $stripePublishableKey,
        private readonly string $adhesionProductId,
        private readonly string $donationProductId,
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

    public function getProduct(string $productId): \Stripe\Product
    {
        return $this->stripeClient->products->retrieve($productId);
    }

    public function fetchProductsByCategory(string $category): array
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

            $products[] = [
                'product' => $product,
                'prices' => $prices->data,
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
        string $priceId,
        string $priceLookupKey,
        int $adhesionAmountCents,
        int $donationAmountCents,
        string $successUrl,
        string $cancelUrl,
    ): Session {
        $lineItems = [];

        $lineItems[] = ['price' => $priceId, 'quantity' => 1];

        if ($adhesionAmountCents > 0 && !$this->hasMembership($email)) {
            $lineItems[] = [
                'price_data' => [
                    'currency' => 'eur',
                    'unit_amount' => $adhesionAmountCents,
                    'product' => $this->adhesionProductId,
                ],
                'quantity' => 1,
            ];
        }

        if ($donationAmountCents > 0) {
            $lineItems[] = [
                'price_data' => [
                    'currency' => 'eur',
                    'unit_amount' => $donationAmountCents,
                    'product' => $this->donationProductId,
                ],
                'quantity' => 1,
            ];
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

        if ($this->isRecurringPrice($priceLookupKey)) {
            $params['mode'] = 'subscription';
            $cancelAt = $this->computeCancelAt($priceLookupKey);
            if ($cancelAt) {
                $params['subscription_data'] = [
                    'metadata' => ['cancel_at' => (string) $cancelAt],
                ];
            }
        } else {
            $params['mode'] = 'payment';
        }

        return $this->stripeClient->checkout->sessions->create($params);
    }

    private function isRecurringPrice(string $lookupKey): bool
    {
        return (bool) preg_match('/-(\d+)x$/', $lookupKey);
    }

    private function computeCancelAt(string $lookupKey): ?int
    {
        if (!preg_match('/-(?:mensuel|trimestriel)-(\d+)x$/', $lookupKey, $matches)) {
            return null;
        }

        $installments = (int) $matches[1];
        $now = new \DateTimeImmutable();

        if (str_contains($lookupKey, '-trimestriel-')) {
            $interval = $installments * 3;
        } else {
            $interval = $installments;
        }

        return $now->modify("+{$interval} months")->getTimestamp();
    }
}
