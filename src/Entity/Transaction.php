<?php
declare(strict_types=1);

namespace App\Entity;

use App\Repository\TransactionRepository;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\DBAL\Types\Types;
use App\Entity\User;

#[ORM\Entity(repositoryClass: TransactionRepository::class)]
#[ORM\Table(name: 'transactions')]
class Transaction
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $date;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $description;

    #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
    private ?string $category = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $amount = '0.00';

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isIncome = false;

    #[ORM\Column(type: Types::STRING, length: 3)]
    private string $currency = 'MNT';

    // Upload-ийн эх сурвалж (КАСС/BANK)
    #[ORM\Column(type: Types::STRING, length: 16, nullable: true)]
    private ?string $origin = null;

    // Харилцагч
    #[ORM\Column(type: Types::STRING, length: 120, nullable: true)]
    private ?string $customer = null;

    // Хэрэглэгчтэй N:1 (User::transactions-тай уялна), устгахад каскаддах
    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'transactions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    public function getId(): ?int { return $this->id; }

    public function getDate(): \DateTimeImmutable { return $this->date; }
    public function setDate(\DateTimeImmutable $date): self { $this->date = $date; return $this; }

    public function getDescription(): string { return $this->description; }
    public function setDescription(string $description): self { $this->description = $description; return $this; }

    public function getCategory(): ?string { return $this->category; }
    public function setCategory(?string $category): self { $this->category = $category; return $this; }

    public function getAmount(): string { return $this->amount; }
    public function setAmount(string $amount): self { $this->amount = $amount; return $this; }

    public function isIncome(): bool { return $this->isIncome; }
    public function setIsIncome(bool $isIncome): self { $this->isIncome = $isIncome; return $this; }

    public function getCurrency(): string { return $this->currency; }
    public function setCurrency(string $currency): self { $this->currency = $currency; return $this; }

    public function getOrigin(): ?string { return $this->origin; }
    public function setOrigin(?string $origin): self
    {
        $this->origin = $origin ? strtoupper($origin) : null;
        return $this;
    }

    public function getCustomer(): ?string { return $this->customer; }
    public function setCustomer(?string $c): self { $this->customer = $c ?: null; return $this; }

    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): self { $this->user = $user; return $this; }
}
