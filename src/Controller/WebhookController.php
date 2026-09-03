<?php

namespace App\Controller;

use App\Entity\Membership;
use App\Repository\MembershipRepository;
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
    public function __construct(
        private readonly StripeWebhookService $webhookService,
        private readonly StripeCheckoutService $checkoutService,
        private readonly MembershipRepository $membershipRepository,
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
        $adhesionAmountCents = (int) ($session->metadata['adhesion_amount_cents'] ?? 0);

        if ($adhesionAmountCents <= 0) {
            return;
        }

        $email = mb_strtolower($session->customer_email ?? '');
        $schoolYear = $session->metadata['school_year'] ?? $this->checkoutService->getCurrentSchoolYear();
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
