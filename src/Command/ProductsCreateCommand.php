<?php

namespace App\Command;

use Stripe\StripeClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'opp:products:create', description: 'Create Stripe products and prices from data/products.csv')]
class ProductsCreateCommand extends Command
{
    private const RHYTHMS = [
        '1x' => null,
        '3x' => ['interval' => 'month', 'interval_count' => 3],
        '10x' => ['interval' => 'month', 'interval_count' => 1],
    ];

    private const INSTALLMENTS = [
        '1x' => null,
        '3x' => 3,
        '10x' => 10,
    ];

    public function __construct(
        private readonly StripeClient $stripeClient,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('season', InputArgument::REQUIRED, 'Season (e.g. 2026-2027)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview without creating anything in Stripe')
            ->addOption('archive-old', null, InputOption::VALUE_NONE, 'Archive prices from previous seasons');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $season = $input->getArgument('season');
        $dryRun = $input->getOption('dry-run');
        $archiveOld = $input->getOption('archive-old');

        if (!preg_match('/^\d{4}-\d{4}$/', $season)) {
            $io->error("Invalid season format: $season (expected YYYY-YYYY)");
            return Command::FAILURE;
        }

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

        $existingProducts = $this->fetchExistingProducts();

        $createdProducts = 0;
        $createdPrices = 0;
        $skippedProducts = 0;
        $skippedPrices = 0;
        $archivedPrices = 0;

        foreach ($rows as $row) {
            $productName = $row['product_name'];
            $existingProduct = $existingProducts[$productName] ?? null;

            if ($existingProduct) {
                $io->text("  ⏭ Product exists: <info>$productName</info> ({$existingProduct->id})");
                $skippedProducts++;
                $stripeProduct = $existingProduct;
            } else {
                $metadata = ['opp_category' => $row['opp_category']];
                if (!empty($row['opp_reduced_by'])) {
                    $metadata['opp_reduced_by'] = $row['opp_reduced_by'];
                }

                $io->text("  ✚ Creating product: <info>$productName</info>");
                if (!$dryRun) {
                    $stripeProduct = $this->stripeClient->products->create([
                        'name' => $productName,
                        'description' => $row['description'],
                        'metadata' => $metadata,
                    ]);
                    $io->text("    → {$stripeProduct->id}");
                } else {
                    $stripeProduct = null;
                }
                $createdProducts++;
            }

            $existingLookupKeys = $this->fetchExistingPriceLookupKeys($stripeProduct?->id);
            $slug = $row['slug'];

            foreach (self::RHYTHMS as $rhythm => $recurring) {
                foreach ([false, true] as $reduced) {
                    $priceCol = $reduced ? "price_{$rhythm}_reduc" : "price_{$rhythm}";
                    $amountCents = (int) ($row[$priceCol] ?? 0);

                    if ($amountCents === 0) {
                        continue;
                    }

                    $lookupKey = "{$slug}-{$season}-{$rhythm}" . ($reduced ? '-reduc' : '');

                    if (\in_array($lookupKey, $existingLookupKeys, true)) {
                        $io->text("    ⏭ Price exists: <comment>$lookupKey</comment>");
                        $skippedPrices++;
                        continue;
                    }

                    $metadata = ['opp_season' => $season];
                    $installments = self::INSTALLMENTS[$rhythm];
                    if ($installments !== null) {
                        $metadata['opp_installments'] = (string) $installments;
                    }
                    if ($reduced) {
                        $metadata['opp_reduced'] = 'true';
                    }

                    $nickname = "{$productName} — {$season} {$rhythm}" . ($reduced ? ' [réduit]' : '');

                    $priceData = [
                        'currency' => 'eur',
                        'unit_amount' => $amountCents,
                        'lookup_key' => $lookupKey,
                        'nickname' => $nickname,
                        'metadata' => $metadata,
                    ];

                    if ($stripeProduct) {
                        $priceData['product'] = $stripeProduct->id;
                    }

                    if ($recurring !== null) {
                        $priceData['recurring'] = $recurring;
                    }

                    $displayAmount = number_format($amountCents / 100, 2) . ' €';
                    $io->text("    ✚ Creating price: <comment>$lookupKey</comment> ($displayAmount)");

                    if (!$dryRun && $stripeProduct) {
                        $price = $this->stripeClient->prices->create($priceData);
                        $io->text("      → {$price->id}");
                    }
                    $createdPrices++;
                }
            }

            if ($archiveOld && $stripeProduct) {
                $archivedPrices += $this->archiveOldPrices($stripeProduct->id, $season, $dryRun, $io);
            }
        }

        $io->newLine();
        $headers = ['', 'Products', 'Prices'];
        $tableRows = [
            ['Created', $createdProducts, $createdPrices],
            ['Skipped', $skippedProducts, $skippedPrices],
        ];
        if ($archiveOld) {
            $tableRows[] = ['Archived', '—', $archivedPrices];
        }
        $io->table($headers, $tableRows);

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
        $headers = fgetcsv($handle, escape: '\\');
        $rows = [];

        while (($data = fgetcsv($handle, escape: '\\')) !== false) {
            if (\count($data) !== \count($headers)) {
                continue;
            }
            $rows[] = array_combine($headers, $data);
        }

        fclose($handle);

        return $rows;
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
            fn($price) => $price->lookup_key,
            $prices->data,
        ));
    }

    private function archiveOldPrices(string $productId, string $currentSeason, bool $dryRun, SymfonyStyle $io): int
    {
        $prices = $this->stripeClient->prices->all([
            'product' => $productId,
            'active' => true,
            'limit' => 100,
        ]);

        $archived = 0;
        foreach ($prices->data as $price) {
            $priceSeason = $price->metadata['opp_season'] ?? null;
            if ($priceSeason !== null && $priceSeason !== $currentSeason) {
                $io->text("    ⊘ Archiving old price: <comment>{$price->lookup_key}</comment> (season: $priceSeason)");
                if (!$dryRun) {
                    $this->stripeClient->prices->update($price->id, ['active' => false]);
                }
                $archived++;
            }
        }

        return $archived;
    }
}
