<?php

namespace App\Twig;

use Stripe\Price;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class AppExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('price_label', $this->priceLabel(...)),
        ];
    }

    public function priceLabel(Price $price): string
    {
        if (!$price->unit_amount) {
            return 'Prix libre';
        }

        $amount = number_format($price->unit_amount / 100, 2, ',', ' ') . ' €';

        if (!$price->recurring) {
            return $amount;
        }

        $lookupKey = $price->lookup_key ?? '';

        if (preg_match('/-(\d+)x$/', $lookupKey, $matches)) {
            $installments = $matches[1];
            $intervalLabel = match ($price->recurring->interval) {
                'month' => $price->recurring->interval_count === 1 ? 'mensuel' : 'trimestriel',
                default => '',
            };
            return "$amount × {$installments} ($intervalLabel)";
        }

        return match ($price->recurring->interval) {
            'month' => $amount . '/mois',
            'year' => $amount . '/an',
            default => $amount,
        };
    }
}
