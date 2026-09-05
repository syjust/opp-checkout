<?php

namespace App\Repository;

use App\Entity\Purchase;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Purchase>
 */
class PurchaseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Purchase::class);
    }

    public function save(Purchase $purchase): void
    {
        $this->getEntityManager()->persist($purchase);
        $this->getEntityManager()->flush();
    }

    /**
     * @return Purchase[]
     */
    public function findByEmailAndYear(string $email, string $schoolYear): array
    {
        return $this->findBy([
            'email' => mb_strtolower($email),
            'schoolYear' => $schoolYear,
        ]);
    }

    public function hasProductForYear(string $email, string $schoolYear, string $stripeProductId): bool
    {
        return null !== $this->findOneBy([
            'email' => mb_strtolower($email),
            'schoolYear' => $schoolYear,
            'stripeProductId' => $stripeProductId,
        ]);
    }

    /**
     * @param string[] $lookupKeyPrefixes
     */
    public function hasAnyMatchingLookupKeyPrefix(string $email, string $schoolYear, array $lookupKeyPrefixes): bool
    {
        $qb = $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.email = :email')
            ->andWhere('p.schoolYear = :schoolYear')
            ->setParameter('email', mb_strtolower($email))
            ->setParameter('schoolYear', $schoolYear);

        $orConditions = $qb->expr()->orX();
        foreach ($lookupKeyPrefixes as $i => $prefix) {
            $orConditions->add($qb->expr()->like('p.lookupKey', ":prefix{$i}"));
            $qb->setParameter("prefix{$i}", $prefix . '%');
        }
        $qb->andWhere($orConditions);

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }
}
