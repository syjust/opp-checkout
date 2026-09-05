<?php

namespace App\Tests\Repository;

use App\Entity\Purchase;
use App\Repository\PurchaseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class PurchaseRepositoryTest extends KernelTestCase
{
    private PurchaseRepository $repository;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->repository = self::getContainer()->get(PurchaseRepository::class);

        $schemaTool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testSaveAndFind(): void
    {
        $purchase = new Purchase(
            'alice@example.com',
            '2026-2027',
            'prod_abc',
            'price_123',
            'cours-instruments-2026-2027-10x',
            'cs_session_1',
        );

        $this->repository->save($purchase);

        $this->assertNotNull($purchase->getId());

        $results = $this->repository->findByEmailAndYear('alice@example.com', '2026-2027');
        $this->assertCount(1, $results);
        $this->assertSame('prod_abc', $results[0]->getStripeProductId());
    }

    public function testFindByEmailAndYearIsCaseInsensitive(): void
    {
        $this->repository->save(new Purchase(
            'Alice@Example.COM',
            '2026-2027',
            'prod_abc',
            'price_123',
            'cours-instruments-2026-2027-10x',
            'cs_session_1',
        ));

        $results = $this->repository->findByEmailAndYear('alice@example.com', '2026-2027');
        $this->assertCount(1, $results);
    }

    public function testFindByEmailAndYearFiltersYear(): void
    {
        $this->repository->save(new Purchase(
            'alice@example.com',
            '2025-2026',
            'prod_abc',
            'price_123',
            'cours-instruments-2025-2026-10x',
            'cs_session_1',
        ));

        $results = $this->repository->findByEmailAndYear('alice@example.com', '2026-2027');
        $this->assertCount(0, $results);
    }

    public function testHasProductForYear(): void
    {
        $this->repository->save(new Purchase(
            'alice@example.com',
            '2026-2027',
            'prod_abc',
            'price_123',
            'cours-instruments-2026-2027-10x',
            'cs_session_1',
        ));

        $this->assertTrue($this->repository->hasProductForYear('alice@example.com', '2026-2027', 'prod_abc'));
        $this->assertFalse($this->repository->hasProductForYear('alice@example.com', '2026-2027', 'prod_other'));
        $this->assertFalse($this->repository->hasProductForYear('bob@example.com', '2026-2027', 'prod_abc'));
    }

    public function testHasAnyMatchingLookupKeyPrefix(): void
    {
        $this->repository->save(new Purchase(
            'alice@example.com',
            '2026-2027',
            'prod_abc',
            'price_123',
            'cours-instruments-2026-2027-10x',
            'cs_session_1',
        ));

        $this->assertTrue(
            $this->repository->hasAnyMatchingLookupKeyPrefix(
                'alice@example.com',
                '2026-2027',
                ['cours-instruments', 'accordeon-aix'],
            )
        );

        $this->assertFalse(
            $this->repository->hasAnyMatchingLookupKeyPrefix(
                'alice@example.com',
                '2026-2027',
                ['cafe-belsunce'],
            )
        );
    }

    public function testHasAnyMatchingLookupKeyPrefixIsCaseInsensitive(): void
    {
        $this->repository->save(new Purchase(
            'Alice@Example.COM',
            '2026-2027',
            'prod_abc',
            'price_123',
            'accordeon-aix-2026-2027-3x',
            'cs_session_2',
        ));

        $this->assertTrue(
            $this->repository->hasAnyMatchingLookupKeyPrefix(
                'alice@example.com',
                '2026-2027',
                ['accordeon-aix'],
            )
        );
    }

    public function testMultiplePurchasesSameEmailYear(): void
    {
        $this->repository->save(new Purchase(
            'alice@example.com',
            '2026-2027',
            'prod_abc',
            'price_123',
            'cours-instruments-2026-2027-10x',
            'cs_session_1',
        ));
        $this->repository->save(new Purchase(
            'alice@example.com',
            '2026-2027',
            'prod_def',
            'price_456',
            'guinguette-marseille-2x-2026-2027-10x-reduc',
            'cs_session_1',
        ));

        $results = $this->repository->findByEmailAndYear('alice@example.com', '2026-2027');
        $this->assertCount(2, $results);
    }
}
