<?php

namespace App\Entity;

use App\Repository\MembershipRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MembershipRepository::class)]
#[ORM\UniqueConstraint(name: 'unique_email_school_year', columns: ['email', 'school_year'])]
class Membership
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $email;

    #[ORM\Column(length: 9)]
    private string $schoolYear;

    #[ORM\Column]
    private int $amountCents;

    #[ORM\Column(length: 255)]
    private string $stripeCustomerId;

    #[ORM\Column(length: 255)]
    private string $stripeSessionId;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $paidAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $email,
        string $schoolYear,
        int $amountCents,
        string $stripeCustomerId,
        string $stripeSessionId,
        \DateTimeImmutable $paidAt,
    ) {
        $this->email = $email;
        $this->schoolYear = $schoolYear;
        $this->amountCents = $amountCents;
        $this->stripeCustomerId = $stripeCustomerId;
        $this->stripeSessionId = $stripeSessionId;
        $this->paidAt = $paidAt;
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

    public function getAmountCents(): int
    {
        return $this->amountCents;
    }

    public function getStripeCustomerId(): string
    {
        return $this->stripeCustomerId;
    }

    public function getStripeSessionId(): string
    {
        return $this->stripeSessionId;
    }

    public function getPaidAt(): \DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
