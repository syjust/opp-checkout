<?php

namespace App\Controller;

use App\Service\StripeCheckoutService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class CheckoutController extends AbstractController
{
    public function __construct(
        private readonly StripeCheckoutService $checkoutService,
    ) {
    }

    #[Route('/', name: 'checkout_index')]
    public function index(): Response
    {
        $season = $this->checkoutService->getCurrentSchoolYear();

        $coursAnnee = $this->checkoutService->fetchProductsByCategory('cours-annee', $season);
        $coursUnite = $this->checkoutService->fetchProductsByCategory('cours-unite', $season);

        $productsAnnee = $this->structureProducts($coursAnnee);
        $productsUnite = $this->structureProducts($coursUnite);

        return $this->render('checkout/index.html.twig', [
            'school_year' => $season,
            'products_annee' => $productsAnnee,
            'products_unite' => $productsUnite,
            'products_annee_json' => json_encode($productsAnnee, \JSON_THROW_ON_ERROR),
        ]);
    }

    #[Route('/api/membership', name: 'api_membership', methods: ['GET'])]
    public function checkMembership(Request $request): JsonResponse
    {
        $email = $request->query->get('email', '');
        if (!$email) {
            return new JsonResponse(['has_membership' => false]);
        }

        return new JsonResponse([
            'has_membership' => $this->checkoutService->hasMembership(mb_strtolower($email)),
        ]);
    }

    #[Route('/create-session', name: 'checkout_create_session', methods: ['POST'])]
    public function createSession(Request $request): Response
    {
        $email = $request->request->get('email');
        $rhythm = $request->request->get('rhythm', '1x');

        $priceIds = $request->request->all('price_ids');
        $singlePriceId = $request->request->get('price_id');
        if (empty($priceIds) && $singlePriceId) {
            $priceIds = [$singlePriceId];
        }
        if (empty($priceIds) || !$email) {
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
            priceIds: $priceIds,
            rhythm: $rhythm,
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

    private function structureProducts(array $rawProducts): array
    {
        $result = [];

        foreach ($rawProducts as $item) {
            $product = $item['product'];
            $prices = $item['prices'];

            $slug = null;
            $pricesByRhythm = [];
            $sessionsCount = null;

            foreach ($prices as $price) {
                $lookupKey = $price->lookup_key ?? '';
                $installments = $price->metadata['opp_installments'] ?? null;
                $reduced = ($price->metadata['opp_reduced'] ?? null) === 'true';

                if ($installments === null && !$price->recurring) {
                    $rhythm = '1x';
                } elseif ($installments === '3') {
                    $rhythm = '3x';
                } elseif ($installments === '10') {
                    $rhythm = '10x';
                } else {
                    $rhythm = '1x';
                }

                if (!$slug && $lookupKey) {
                    $slug = preg_replace('/-\d{4}-\d{4}-.+$/', '', $lookupKey);
                }

                $key = $reduced ? $rhythm . '_reduc' : $rhythm;
                $pricesByRhythm[$key] = [
                    'id' => $price->id,
                    'amount' => $price->unit_amount,
                    'lookup_key' => $lookupKey,
                ];
            }

            $reducedBy = $product->metadata['opp_reduced_by'] ?? null;
            $isGuinguette = $reducedBy !== null;

            $grantsReduction = false;
            foreach ($rawProducts as $other) {
                $otherReducedBy = $other['product']->metadata['opp_reduced_by'] ?? '';
                if ($otherReducedBy && $slug && str_contains($otherReducedBy, $slug)) {
                    $grantsReduction = true;
                    break;
                }
            }

            $result[] = [
                'slug' => $slug ?? $product->id,
                'name' => $product->name,
                'description' => $product->description ?? '',
                'sessions_count' => null,
                'is_guinguette' => $isGuinguette,
                'grants_reduction' => $grantsReduction,
                'reduced_by' => $reducedBy ? explode(';', $reducedBy) : [],
                'prices' => $pricesByRhythm,
            ];
        }

        return $result;
    }
}
