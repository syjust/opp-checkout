<?php

namespace App\Tests\Command;

use App\Command\ProductsCreateCommand;
use PHPUnit\Framework\TestCase;
use Stripe\Collection;
use Stripe\Product;
use Stripe\Price;
use Stripe\Service\ProductService;
use Stripe\Service\PriceService;
use Stripe\StripeClient;
use Symfony\Component\Console\Tester\CommandTester;

class ProductsCreateCommandTest extends TestCase
{
    private StripeClient $stripeClient;
    private ProductService $productService;
    private PriceService $priceService;
    private string $projectDir;

    protected function setUp(): void
    {
        $this->stripeClient = $this->createMock(StripeClient::class);
        $this->productService = $this->createMock(ProductService::class);
        $this->priceService = $this->createMock(PriceService::class);
        $this->stripeClient->products = $this->productService;
        $this->stripeClient->prices = $this->priceService;
        $this->projectDir = \dirname(__DIR__, 2);
    }

    public function testRequiresSeasonArgument(): void
    {
        $command = new ProductsCreateCommand($this->stripeClient, $this->projectDir);
        $tester = new CommandTester($command);

        $this->expectException(\Symfony\Component\Console\Exception\RuntimeException::class);
        $tester->execute([]);
    }

    public function testDryRunCreatesNothing(): void
    {
        $this->productService->method('all')->willReturn($this->emptyCollection());
        $this->productService->expects($this->never())->method('create');
        $this->priceService->expects($this->never())->method('create');

        $command = new ProductsCreateCommand($this->stripeClient, $this->projectDir);
        $tester = new CommandTester($command);
        $tester->execute(['season' => '2026-2027', '--dry-run' => true]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('DRY RUN', $tester->getDisplay());
    }

    public function testSkipsExistingProducts(): void
    {
        $existingProduct = Product::constructFrom(['id' => 'prod_123', 'name' => "Cours d'instruments hebdomadaire"]);

        $this->productService->method('all')->willReturn($this->collectionOf([$existingProduct]));
        $this->priceService->method('all')->willReturn($this->emptyCollection());

        $command = new ProductsCreateCommand($this->stripeClient, $this->projectDir);
        $tester = new CommandTester($command);
        $tester->execute(['season' => '2026-2027', '--dry-run' => true]);

        $this->assertStringContainsString('Product exists', $tester->getDisplay());
    }

    public function testCreatesThreeRhythmsForStandardProduct(): void
    {
        $this->productService->method('all')->willReturn($this->emptyCollection());
        $this->priceService->method('all')->willReturn($this->emptyCollection());

        $createdProduct = Product::constructFrom(['id' => 'prod_new']);
        $this->productService->method('create')->willReturn($createdProduct);

        $createdPrices = [];
        $this->priceService->method('create')->willReturnCallback(function ($data) use (&$createdPrices) {
            $createdPrices[] = $data;
            $price = Price::constructFrom(['id' => 'price_' . count($createdPrices)]);
            return $price;
        });

        $command = new ProductsCreateCommand($this->stripeClient, $this->projectDir);
        $tester = new CommandTester($command);
        $tester->execute(['season' => '2026-2027']);

        $instrumentPrices = array_filter($createdPrices, fn($p) => str_starts_with($p['lookup_key'], 'cours-instruments-'));
        $this->assertCount(3, $instrumentPrices);

        $lookupKeys = array_map(fn($p) => $p['lookup_key'], $instrumentPrices);
        $this->assertContains('cours-instruments-2026-2027-1x', $lookupKeys);
        $this->assertContains('cours-instruments-2026-2027-3x', $lookupKeys);
        $this->assertContains('cours-instruments-2026-2027-10x', $lookupKeys);
    }

    public function testCreatesSixPricesForReducibleProduct(): void
    {
        $this->productService->method('all')->willReturn($this->emptyCollection());
        $this->priceService->method('all')->willReturn($this->emptyCollection());

        $createdProduct = Product::constructFrom(['id' => 'prod_new']);
        $this->productService->method('create')->willReturn($createdProduct);

        $createdPrices = [];
        $this->priceService->method('create')->willReturnCallback(function ($data) use (&$createdPrices) {
            $createdPrices[] = $data;
            $price = Price::constructFrom(['id' => 'price_' . count($createdPrices)]);
            return $price;
        });

        $command = new ProductsCreateCommand($this->stripeClient, $this->projectDir);
        $tester = new CommandTester($command);
        $tester->execute(['season' => '2026-2027']);

        $guinguettePrices = array_filter($createdPrices, fn($p) => str_starts_with($p['lookup_key'], 'guinguette-marseille-2x-'));
        $this->assertCount(6, $guinguettePrices);

        $lookupKeys = array_map(fn($p) => $p['lookup_key'], $guinguettePrices);
        $this->assertContains('guinguette-marseille-2x-2026-2027-1x', $lookupKeys);
        $this->assertContains('guinguette-marseille-2x-2026-2027-3x', $lookupKeys);
        $this->assertContains('guinguette-marseille-2x-2026-2027-10x', $lookupKeys);
        $this->assertContains('guinguette-marseille-2x-2026-2027-1x-reduc', $lookupKeys);
        $this->assertContains('guinguette-marseille-2x-2026-2027-3x-reduc', $lookupKeys);
        $this->assertContains('guinguette-marseille-2x-2026-2027-10x-reduc', $lookupKeys);
    }

    public function testPriceMetadataIsCorrect(): void
    {
        $this->productService->method('all')->willReturn($this->emptyCollection());
        $this->priceService->method('all')->willReturn($this->emptyCollection());

        $createdProduct = Product::constructFrom(['id' => 'prod_new']);
        $this->productService->method('create')->willReturn($createdProduct);

        $createdPrices = [];
        $this->priceService->method('create')->willReturnCallback(function ($data) use (&$createdPrices) {
            $createdPrices[] = $data;
            $price = Price::constructFrom(['id' => 'price_' . count($createdPrices)]);
            return $price;
        });

        $command = new ProductsCreateCommand($this->stripeClient, $this->projectDir);
        $tester = new CommandTester($command);
        $tester->execute(['season' => '2026-2027']);

        $monthly = current(array_filter($createdPrices, fn($p) => $p['lookup_key'] === 'cours-instruments-2026-2027-10x'));
        $this->assertNotFalse($monthly);
        $this->assertSame('2026-2027', $monthly['metadata']['opp_season']);
        $this->assertSame('10', $monthly['metadata']['opp_installments']);
        $this->assertArrayNotHasKey('opp_reduced', $monthly['metadata']);
        $this->assertSame(5850, $monthly['unit_amount']);
        $this->assertSame('month', $monthly['recurring']['interval']);
        $this->assertSame(1, $monthly['recurring']['interval_count']);

        $oneTime = current(array_filter($createdPrices, fn($p) => $p['lookup_key'] === 'cours-instruments-2026-2027-1x'));
        $this->assertNotFalse($oneTime);
        $this->assertArrayNotHasKey('recurring', $oneTime);
        $this->assertArrayNotHasKey('opp_installments', $oneTime['metadata']);
        $this->assertSame(58500, $oneTime['unit_amount']);

        $reduced = current(array_filter($createdPrices, fn($p) => $p['lookup_key'] === 'guinguette-marseille-2x-2026-2027-10x-reduc'));
        $this->assertNotFalse($reduced);
        $this->assertSame('true', $reduced['metadata']['opp_reduced']);
        $this->assertSame(3000, $reduced['unit_amount']);
    }

    public function testNoPricesForAdhesionAndDon(): void
    {
        $this->productService->method('all')->willReturn($this->emptyCollection());
        $this->priceService->method('all')->willReturn($this->emptyCollection());

        $createdProduct = Product::constructFrom(['id' => 'prod_new']);
        $this->productService->method('create')->willReturn($createdProduct);

        $createdPrices = [];
        $this->priceService->method('create')->willReturnCallback(function ($data) use (&$createdPrices) {
            $createdPrices[] = $data;
            $price = Price::constructFrom(['id' => 'price_' . count($createdPrices)]);
            return $price;
        });

        $command = new ProductsCreateCommand($this->stripeClient, $this->projectDir);
        $tester = new CommandTester($command);
        $tester->execute(['season' => '2026-2027']);

        $adhesionPrices = array_filter($createdPrices, fn($p) => str_starts_with($p['lookup_key'], 'adhesion-'));
        $donPrices = array_filter($createdPrices, fn($p) => str_starts_with($p['lookup_key'], 'don-'));
        $this->assertEmpty($adhesionPrices);
        $this->assertEmpty($donPrices);
    }

    public function testProductMetadataIncludesReducedBy(): void
    {
        $this->productService->method('all')->willReturn($this->emptyCollection());
        $this->priceService->method('all')->willReturn($this->emptyCollection());

        $createdProducts = [];
        $this->productService->method('create')->willReturnCallback(function ($data) use (&$createdProducts) {
            $createdProducts[] = $data;
            $product = Product::constructFrom(['id' => 'prod_' . count($createdProducts)]);
            return $product;
        });

        $this->priceService->method('create')->willReturnCallback(function () {
            static $i = 0;
            $price = Price::constructFrom(['id' => 'price_' . ++$i]);
            return $price;
        });

        $command = new ProductsCreateCommand($this->stripeClient, $this->projectDir);
        $tester = new CommandTester($command);
        $tester->execute(['season' => '2026-2027']);

        $guinguette = current(array_filter($createdProducts, fn($p) => str_contains($p['name'], 'Guinguette Orchestra Marseille (2')));
        $this->assertNotFalse($guinguette);
        $this->assertSame('cours-annee', $guinguette['metadata']['opp_category']);
        $this->assertSame('cours-instruments;accordeon-aix;accordeon-aubagne', $guinguette['metadata']['opp_reduced_by']);

        $instruments = current(array_filter($createdProducts, fn($p) => str_contains($p['name'], 'instruments')));
        $this->assertNotFalse($instruments);
        $this->assertArrayNotHasKey('opp_reduced_by', $instruments['metadata']);
    }

    public function testCoursParticulierHasOnly1xPrice(): void
    {
        $this->productService->method('all')->willReturn($this->emptyCollection());
        $this->priceService->method('all')->willReturn($this->emptyCollection());

        $createdProduct = Product::constructFrom(['id' => 'prod_new']);
        $this->productService->method('create')->willReturn($createdProduct);

        $createdPrices = [];
        $this->priceService->method('create')->willReturnCallback(function ($data) use (&$createdPrices) {
            $createdPrices[] = $data;
            $price = Price::constructFrom(['id' => 'price_' . count($createdPrices)]);
            return $price;
        });

        $command = new ProductsCreateCommand($this->stripeClient, $this->projectDir);
        $tester = new CommandTester($command);
        $tester->execute(['season' => '2026-2027']);

        $cpPrices = array_filter($createdPrices, fn($p) => str_starts_with($p['lookup_key'], 'cours-particulier-1h-'));
        $this->assertCount(1, $cpPrices);
        $cp = current($cpPrices);
        $this->assertSame('cours-particulier-1h-2026-2027-1x', $cp['lookup_key']);
        $this->assertSame(2500, $cp['unit_amount']);
        $this->assertArrayNotHasKey('recurring', $cp);
    }

    private function emptyCollection(): Collection
    {
        $collection = new Collection();
        $collection->data = [];
        return $collection;
    }

    private function collectionOf(array $items): Collection
    {
        $collection = new Collection();
        $collection->data = $items;
        return $collection;
    }
}
