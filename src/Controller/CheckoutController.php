<?php

namespace App\Controller;

use App\Service\StripeCheckoutService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class CheckoutController extends AbstractController
{
    private const CATEGORIES = [
        'cours-annee' => 'Cours à l\'année',
        'cours-unite' => 'Cours à l\'unité',
        'stage' => 'Stages',
    ];

    public function __construct(
        private readonly StripeCheckoutService $checkoutService,
    ) {
    }

    #[Route('/', name: 'checkout_index')]
    public function index(): Response
    {
        $productsByCategory = [];
        foreach (array_keys(self::CATEGORIES) as $category) {
            $productsByCategory[$category] = $this->checkoutService->fetchProductsByCategory($category);
        }

        return $this->render('checkout/index.html.twig', [
            'categories' => self::CATEGORIES,
            'products_by_category' => $productsByCategory,
            'school_year' => $this->checkoutService->getCurrentSchoolYear(),
        ]);
    }

    #[Route('/inscription', name: 'checkout_email')]
    public function email(Request $request): Response
    {
        $priceId = $request->query->get('price_id');
        $lookupKey = $request->query->get('lookup_key', '');

        if (!$priceId) {
            return $this->redirectToRoute('checkout_index');
        }

        $price = $this->checkoutService->getPrice($priceId);
        if (!$price) {
            return $this->redirectToRoute('checkout_index');
        }

        $product = $this->checkoutService->getProduct($price->product);

        $email = $request->query->get('email', '');
        $hasMembership = $email ? $this->checkoutService->hasMembership($email) : false;

        return $this->render('checkout/email.html.twig', [
            'price_id' => $priceId,
            'lookup_key' => $lookupKey,
            'product_name' => $product->name,
            'price_description' => $this->formatPriceDescription($price),
            'email' => $email,
            'has_membership' => $hasMembership,
            'school_year' => $this->checkoutService->getCurrentSchoolYear(),
        ]);
    }

    #[Route('/create-session', name: 'checkout_create_session', methods: ['POST'])]
    public function createSession(Request $request): Response
    {
        $priceId = $request->request->get('price_id');
        $lookupKey = $request->request->get('lookup_key', '');
        $email = $request->request->get('email');

        if (!$priceId || !$email) {
            return $this->redirectToRoute('checkout_index');
        }

        $adhesionAmount = max(0, (int) $request->request->get('adhesion_amount', 0));
        $adhesionAmountCents = $adhesionAmount * 100;

        $donationPreset = $request->request->get('donation_preset', '0');
        if ($donationPreset === 'custom') {
            $donationAmount = max(0, (int) $request->request->get('donation_custom_amount', 0));
        } else {
            $donationAmount = max(0, (int) $donationPreset);
        }
        $donationAmountCents = $donationAmount * 100;

        $session = $this->checkoutService->createCheckoutSession(
            email: mb_strtolower($email),
            priceId: $priceId,
            priceLookupKey: $lookupKey,
            adhesionAmountCents: $adhesionAmountCents,
            donationAmountCents: $donationAmountCents,
            successUrl: $this->generateUrl('checkout_success', [], UrlGeneratorInterface::ABSOLUTE_URL),
            cancelUrl: $this->generateUrl('checkout_cancel', [], UrlGeneratorInterface::ABSOLUTE_URL),
        );

        return $this->redirect($session->url);
    }

    #[Route('/success', name: 'checkout_success')]
    public function success(): Response
    {
        return $this->render('checkout/success.html.twig');
    }

    #[Route('/cancel', name: 'checkout_cancel')]
    public function cancel(): Response
    {
        return $this->render('checkout/cancel.html.twig');
    }

    private function formatPriceDescription(\Stripe\Price $price): string
    {
        if (!$price->unit_amount) {
            return 'Prix libre';
        }

        $amount = number_format($price->unit_amount / 100, 2, ',', ' ') . ' €';

        if ($price->recurring) {
            $interval = match ($price->recurring->interval) {
                'month' => $price->recurring->interval_count === 1 ? '/mois' : "/ {$price->recurring->interval_count} mois",
                'year' => '/an',
                default => '',
            };
            return $amount . ' ' . $interval;
        }

        return $amount;
    }
}
