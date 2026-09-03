<?php

namespace App\Twig;

use Stripe\Price;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use Twig\TwigFilter;

class AppExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('price_label', $this->priceLabel(...)),
            new TwigFunction('price_annual_total', $this->priceAnnualTotal(...)),
            new TwigFunction('is_reduced_price', $this->isReducedPrice(...)),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('format_euros', $this->formatEuros(...)),
        ];
    }

    public function priceLabel(Price $price): string
    {
        if (!$price->unit_amount) {
            return 'Prix libre';
        }

        $amount = $this->formatEuros($price->unit_amount);

        if (!$price->recurring) {
            return $amount;
        }

        $installments = $this->parseInstallments($price->lookup_key ?? '');

        if ($installments) {
            if ($price->recurring->interval_count === 1) {
                return "{$amount}/mois x{$installments}";
            }

            return "{$amount}/trimestre x{$installments}";
        }

        return match ($price->recurring->interval) {
            'month' => $amount . '/mois',
            'year' => $amount . '/an',
            default => $amount,
        };
    }

    public function priceAnnualTotal(Price $price): ?int
    {
        if (!$price->unit_amount || !$price->recurring) {
            return null;
        }

        $installments = $this->parseInstallments($price->lookup_key ?? '');
        if (!$installments) {
            return null;
        }

        return $price->unit_amount * $installments;
    }

    public function isReducedPrice(Price $price): bool
    {
        return str_contains($price->lookup_key ?? '', '-reduc-');
    }

    public function formatEuros(int $cents): string
    {
        return number_format($cents / 100, 0, ',', ' ') . ' €';
    }

    private function parseInstallments(string $lookupKey): ?int
    {
        if (preg_match('/-(\d+)x$/', $lookupKey, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }
}
