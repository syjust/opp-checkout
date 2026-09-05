<?php

namespace App\Twig;

use Stripe\Price;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use Twig\TwigFilter;

class AppExtension extends AbstractExtension
{
    private ?string $cachedVersion = null;

    public function __construct(
        private readonly string $projectDir,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('price_label', $this->priceLabel(...)),
            new TwigFunction('price_annual_total', $this->priceAnnualTotal(...)),
            new TwigFunction('is_reduced_price', $this->isReducedPrice(...)),
            new TwigFunction('app_version', $this->appVersion(...)),
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

        $installments = (int) ($price->metadata['opp_installments'] ?? 0);

        if ($installments > 0) {
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
        $installments = (int) ($price->metadata['opp_installments'] ?? 0);

        if (!$price->unit_amount) {
            return null;
        }

        if (!$price->recurring) {
            return $price->unit_amount;
        }

        if ($installments <= 0) {
            return null;
        }

        return $price->unit_amount * $installments;
    }

    public function isReducedPrice(Price $price): bool
    {
        return ($price->metadata['opp_reduced'] ?? null) === 'true';
    }

    public function formatEuros(int $cents): string
    {
        return number_format($cents / 100, 0, ',', ' ') . ' €';
    }

    public function appVersion(): string
    {
        if ($this->cachedVersion !== null) {
            return $this->cachedVersion;
        }

        $versionFile = $this->projectDir . '/VERSION';
        if (file_exists($versionFile)) {
            return $this->cachedVersion = trim(file_get_contents($versionFile));
        }

        $branch = trim(shell_exec('git -C ' . escapeshellarg($this->projectDir) . ' rev-parse --abbrev-ref HEAD 2>/dev/null') ?: '');
        $hash = trim(shell_exec('git -C ' . escapeshellarg($this->projectDir) . ' rev-parse --short HEAD 2>/dev/null') ?: '');

        if ($hash) {
            return $this->cachedVersion = 'dev' . ($branch ? "-{$branch}" : '') . "-{$hash}";
        }

        return $this->cachedVersion = 'dev';
    }
}
