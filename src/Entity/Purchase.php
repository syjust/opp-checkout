<?php

namespace App\Entity;

use App\Repository\PurchaseRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PurchaseRepository::class)]
class Purchase
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $email;

    #[ORM\Column(length: 9)]
    private string $schoolYear;

    #[ORM\Column(length: 255)]
    private string $stripeProductId;

    #[ORM\Column(length: 255)]
    private string $stripePriceId;

    #[ORM\Column(length: 255)]
    private string $lookupKey;

    #[ORM\Column(length: 255)]
    private string $checkoutSessionId;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $email,
        string $schoolYear,
        string $stripeProductId,
        string $stripePriceId,
        string $lookupKey,
        string $checkoutSessionId,
    ) {
        $this->email = mb_strtolower($email);
        $this->schoolYear = $schoolYear;
        $this->stripeProductId = $stripeProductId;
        $this->stripePriceId = $stripePriceId;
        $this->lookupKey = $lookupKey;
        $this->checkoutSessionId = $checkoutSessionId;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getSchoolYear(): string
    {
        return $this->schoolYear;
    }

    public function getStripeProductId(): string
    {
        return $this->stripeProductId;
    }

    public function getStripePriceId(): string
    {
        return $this->stripePriceId;
    }

    public function getLookupKey(): string
    {
        return $this->lookupKey;
    }

    public function getCheckoutSessionId(): string
    {
        return $this->checkoutSessionId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
