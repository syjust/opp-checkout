<?php

namespace App\Controller;

use App\Entity\Membership;
use App\Entity\Purchase;
use App\Repository\MembershipRepository;
use App\Repository\PurchaseRepository;
use App\Service\StripeCheckoutService;
use App\Service\StripeWebhookService;
use Psr\Log\LoggerInterface;
use Stripe\StripeClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class WebhookController extends AbstractController
{
    private const RECORDABLE_CATEGORIES = ['cours-annee', 'cours-unite', 'stage'];

    public function __construct(
        private readonly StripeWebhookService $webhookService,
        private readonly StripeCheckoutService $checkoutService,
        private readonly MembershipRepository $membershipRepository,
        private readonly PurchaseRepository $purchaseRepository,
        private readonly StripeClient $stripeClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/webhook/stripe', name: 'webhook_stripe', methods: ['POST'])]
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $sigHeader = $request->headers->get('Stripe-Signature', '');

        try {
            $event = $this->webhookService->constructEvent($payload, $sigHeader);
        } catch (\Exception $e) {
            $this->logger->error('Stripe webhook signature verification failed', [
                'error' => $e->getMessage(),
            ]);
            return new JsonResponse(['error' => 'Invalid signature'], Response::HTTP_BAD_REQUEST);
        }

        match ($event->type) {
            'checkout.session.completed' => $this->handleCheckoutCompleted($event->data->object),
            'invoice.paid' => $this->logger->info('Invoice paid', ['invoice' => $event->data->object->id]),
            'invoice.payment_failed' => $this->logger->warning('Invoice payment failed', ['invoice' => $event->data->object->id]),
            default => $this->logger->debug('Unhandled event', ['type' => $event->type]),
        };

        return new JsonResponse(['status' => 'ok']);
    }

    private function handleCheckoutCompleted(object $session): void
    {
        $this->createSubscriptionScheduleIfNeeded($session);
        $this->recordPurchases($session);
        $this->recordMembershipIfNeeded($session);
    }

    private function createSubscriptionScheduleIfNeeded(object $session): void
    {
        $subscriptionId = $session->subscription ?? null;
        if (!$subscriptionId) {
            return;
        }

        $subscription = $this->stripeClient->subscriptions->retrieve($subscriptionId);
        $installments = (int) ($subscription->metadata['opp_installments'] ?? 0);

        if ($installments <= 0) {
            return;
        }

        $item = $subscription->items->data[0] ?? null;
        $interval = $item?->price?->recurring?->interval ?? 'month';
        $intervalCount = $item?->price?->recurring?->interval_count ?? 1;
        $intervalMonths = $interval === 'month' ? $intervalCount : $intervalCount * 12;
        $totalMonths = $installments * $intervalMonths;

        $startDate = (int) $subscription->current_period_start;
        $endDate = (new \DateTimeImmutable("@$startDate"))->modify("+{$totalMonths} months")->getTimestamp();

        $schedule = $this->stripeClient->subscriptionSchedules->create([
            'from_subscription' => $subscriptionId,
        ]);

        $currentPhase = $schedule->phases[0] ?? null;
        $phaseItems = [];
        if ($currentPhase) {
            foreach ($currentPhase->items as $phaseItem) {
                $phaseItems[] = [
                    'price' => $phaseItem->price,
                    'quantity' => $phaseItem->quantity,
                ];
            }
        }

        $this->stripeClient->subscriptionSchedules->update($schedule->id, [
            'end_behavior' => 'cancel',
            'phases' => [
                [
                    'items' => $phaseItems,
                    'start_date' => 'now',
                    'end_date' => $endDate,
                ],
            ],
        ]);

        $this->logger->info('Subscription schedule created', [
            'subscription' => $subscriptionId,
            'schedule' => $schedule->id,
            'installments' => $installments,
            'end_date' => date('Y-m-d', $endDate),
        ]);
    }

    private function recordPurchases(object $session): void
    {
        $email = mb_strtolower($session->customer_email ?? '');
        $schoolYear = $session->metadata->school_year ?? $this->checkoutService->getCurrentSchoolYear();

        if (!$email) {
            return;
        }

        $lineItems = $this->stripeClient->checkout->sessions->allLineItems($session->id, [
            'expand' => ['data.price.product'],
        ]);

        foreach ($lineItems->data as $lineItem) {
            $price = $lineItem->price;
            $product = $price->product;

            $category = $product->metadata['opp_category'] ?? null;
            if (!\in_array($category, self::RECORDABLE_CATEGORIES, true)) {
                continue;
            }

            $purchase = new Purchase(
                email: $email,
                schoolYear: $schoolYear,
                stripeProductId: $product->id,
                stripePriceId: $price->id,
                lookupKey: $price->lookup_key ?? '',
                checkoutSessionId: $session->id,
            );

            $this->purchaseRepository->save($purchase);

            $this->logger->info('Purchase recorded', [
                'email' => $email,
                'product' => $product->id,
                'lookup_key' => $price->lookup_key,
            ]);
        }
    }

    private function recordMembershipIfNeeded(object $session): void
    {
        $adhesionAmountCents = (int) ($session->metadata->adhesion_amount_cents ?? 0);

        if ($adhesionAmountCents <= 0) {
            return;
        }

        $email = mb_strtolower($session->customer_email ?? '');
        $schoolYear = $session->metadata->school_year ?? $this->checkoutService->getCurrentSchoolYear();
        $customerId = $session->customer ?? '';

        if (!$email) {
            $this->logger->warning('Checkout completed without email', ['session' => $session->id]);
            return;
        }

        if ($this->membershipRepository->hasMembershipForYear($email, $schoolYear)) {
            $this->logger->info('Membership already recorded', ['email' => $email, 'year' => $schoolYear]);
            return;
        }

        $membership = new Membership(
            email: $email,
            schoolYear: $schoolYear,
            amountCents: $adhesionAmountCents,
            stripeCustomerId: $customerId,
            stripeSessionId: $session->id,
            paidAt: new \DateTimeImmutable(),
        );

        $this->membershipRepository->save($membership);

        $this->logger->info('Membership recorded', [
            'email' => $email,
            'year' => $schoolYear,
            'amount' => $adhesionAmountCents,
        ]);
    }
}
