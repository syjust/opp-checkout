<?php

namespace App\Tests\Twig;

use App\Twig\AppExtension;
use PHPUnit\Framework\TestCase;
use Stripe\Price;

class AppExtensionTest extends TestCase
{
    private AppExtension $ext;

    protected function setUp(): void
    {
        $this->ext = new AppExtension('/tmp');
    }

    public function testPriceLabelOneTime(): void
    {
        $price = Price::constructFrom([
            'id' => 'price_1x',
            'unit_amount' => 58500,
            'recurring' => null,
            'metadata' => [],
        ]);

        $this->assertSame('585 €', $this->ext->priceLabel($price));
    }

    public function testPriceLabelMonthlyWithInstallments(): void
    {
        $price = Price::constructFrom([
            'id' => 'price_10x',
            'unit_amount' => 5850,
            'recurring' => ['interval' => 'month', 'interval_count' => 1],
            'metadata' => ['opp_installments' => '10'],
        ]);

        $this->assertSame('59 €/mois x10', $this->ext->priceLabel($price));
    }

    public function testPriceLabelQuarterlyWithInstallments(): void
    {
        $price = Price::constructFrom([
            'id' => 'price_3x',
            'unit_amount' => 19500,
            'recurring' => ['interval' => 'month', 'interval_count' => 3],
            'metadata' => ['opp_installments' => '3'],
        ]);

        $this->assertSame('195 €/trimestre x3', $this->ext->priceLabel($price));
    }

    public function testPriceLabelFreePrice(): void
    {
        $price = Price::constructFrom([
            'id' => 'price_free',
            'unit_amount' => 0,
            'recurring' => null,
            'metadata' => [],
        ]);

        $this->assertSame('Prix libre', $this->ext->priceLabel($price));
    }

    public function testPriceAnnualTotalOneTime(): void
    {
        $price = Price::constructFrom([
            'id' => 'price_1x',
            'unit_amount' => 58500,
            'recurring' => null,
            'metadata' => [],
        ]);

        $this->assertSame(58500, $this->ext->priceAnnualTotal($price));
    }

    public function testPriceAnnualTotalMonthly(): void
    {
        $price = Price::constructFrom([
            'id' => 'price_10x',
            'unit_amount' => 5850,
            'recurring' => ['interval' => 'month', 'interval_count' => 1],
            'metadata' => ['opp_installments' => '10'],
        ]);

        $this->assertSame(58500, $this->ext->priceAnnualTotal($price));
    }

    public function testPriceAnnualTotalQuarterly(): void
    {
        $price = Price::constructFrom([
            'id' => 'price_3x',
            'unit_amount' => 19500,
            'recurring' => ['interval' => 'month', 'interval_count' => 3],
            'metadata' => ['opp_installments' => '3'],
        ]);

        $this->assertSame(58500, $this->ext->priceAnnualTotal($price));
    }

    public function testIsReducedPrice(): void
    {
        $reduced = Price::constructFrom([
            'id' => 'price_reduc',
            'metadata' => ['opp_reduced' => 'true'],
        ]);
        $normal = Price::constructFrom([
            'id' => 'price_normal',
            'metadata' => [],
        ]);

        $this->assertTrue($this->ext->isReducedPrice($reduced));
        $this->assertFalse($this->ext->isReducedPrice($normal));
    }

    public function testFormatEuros(): void
    {
        $this->assertSame('585 €', $this->ext->formatEuros(58500));
        $this->assertSame('25 €', $this->ext->formatEuros(2500));
    }
}
