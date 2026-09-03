<?php

namespace App\Command;

use Stripe\StripeClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'opp:products:create', description: 'Create Stripe products and prices from data/products.csv')]
class ProductsCreateCommand extends Command
{
    public function __construct(
        private readonly StripeClient $stripeClient,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview without creating anything in Stripe');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');

        if ($dryRun) {
            $io->warning('DRY RUN — nothing will be created in Stripe');
        }

        $csvPath = $this->projectDir . '/data/products.csv';
        if (!file_exists($csvPath)) {
            $io->error("File not found: $csvPath");
            return Command::FAILURE;
        }

        $rows = $this->parseCsv($csvPath);
        if (empty($rows)) {
            $io->error('No rows found in CSV');
            return Command::FAILURE;
        }

        $grouped = $this->groupByProduct($rows);
        $existingProducts = $this->fetchExistingProducts();

        $createdProducts = 0;
        $createdPrices = 0;
        $skippedProducts = 0;
        $skippedPrices = 0;

        foreach ($grouped as $productName => $productRows) {
            $firstRow = $productRows[0];
            $existingProduct = $existingProducts[$productName] ?? null;

            if ($existingProduct) {
                $io->text("  ⏭ Product exists: <info>$productName</info> ({$existingProduct->id})");
                $skippedProducts++;
                $stripeProduct = $existingProduct;
            } else {
                $io->text("  ✚ Creating product: <info>$productName</info>");
                if (!$dryRun) {
                    $stripeProduct = $this->stripeClient->products->create([
                        'name' => $productName,
                        'description' => $firstRow['description'],
                        'metadata' => ['opp_category' => $firstRow['opp_category']],
                    ]);
                    $io->text("    → {$stripeProduct->id}");
                } else {
                    $stripeProduct = null;
                }
                $createdProducts++;
            }

            $existingPriceLookupKeys = $this->fetchExistingPriceLookupKeys($stripeProduct?->id);

            foreach ($productRows as $row) {
                $lookupKey = $row['price_lookup_key'];

                if (\in_array($lookupKey, $existingPriceLookupKeys, true)) {
                    $io->text("    ⏭ Price exists: <comment>$lookupKey</comment>");
                    $skippedPrices++;
                    continue;
                }

                $priceData = [
                    'currency' => $row['currency'],
                    'lookup_key' => $lookupKey,
                    'metadata' => ['opp_category' => $row['opp_category']],
                ];

                if ($stripeProduct) {
                    $priceData['product'] = $stripeProduct->id;
                }

                if ($row['amount_cents'] > 0) {
                    $priceData['unit_amount'] = (int) $row['amount_cents'];
                }

                if ($row['billing_type'] === 'recurring') {
                    $priceData['recurring'] = [
                        'interval' => $row['interval'],
                        'interval_count' => (int) $row['interval_count'],
                    ];
                }

                $displayAmount = $row['amount_cents'] > 0
                    ? number_format($row['amount_cents'] / 100, 2) . '€'
                    : 'prix libre';

                $io->text("    ✚ Creating price: <comment>$lookupKey</comment> ($displayAmount)");

                if (!$dryRun && $stripeProduct) {
                    $price = $this->stripeClient->prices->create($priceData);
                    $io->text("      → {$price->id}");
                }
                $createdPrices++;
            }
        }

        $io->newLine();
        $io->table(
            ['', 'Products', 'Prices'],
            [
                ['Created', $createdProducts, $createdPrices],
                ['Skipped', $skippedProducts, $skippedPrices],
            ]
        );

        if ($dryRun) {
            $io->info('Dry run complete — re-run without --dry-run to create in Stripe');
        } else {
            $io->success('Done! Check your Stripe Dashboard.');
        }

        return Command::SUCCESS;
    }

    private function parseCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        $headers = fgetcsv($handle);
        $rows = [];

        while (($data = fgetcsv($handle)) !== false) {
            if (\count($data) !== \count($headers)) {
                continue;
            }
            $rows[] = array_combine($headers, $data);
        }

        fclose($handle);

        return $rows;
    }

    private function groupByProduct(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row['product_name']][] = $row;
        }

        return $grouped;
    }

    private function fetchExistingProducts(): array
    {
        $products = [];
        $allProducts = $this->stripeClient->products->all(['active' => true, 'limit' => 100]);

        foreach ($allProducts->data as $product) {
            $products[$product->name] = $product;
        }

        return $products;
    }

    private function fetchExistingPriceLookupKeys(?string $productId): array
    {
        if (!$productId) {
            return [];
        }

        $prices = $this->stripeClient->prices->all([
            'product' => $productId,
            'active' => true,
            'limit' => 100,
        ]);

        return array_filter(array_map(
            fn ($price) => $price->lookup_key,
            $prices->data,
        ));
    }
}
