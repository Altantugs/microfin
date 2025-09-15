<?php
namespace App\Entity;

use App\Repository\TransactionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TransactionRepository::class)]
class Transaction
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $date;

    #[ORM\Column(length:255)]
    private string $description;

    #[ORM\Column(length:100, nullable:true)]
    private ?string $category = null;

    #[ORM\Column(type: "decimal", precision: 15, scale: 2)]
    private string $amount = '0.00';

    #[ORM\Column(type: "boolean")]
    private bool $isIncome = false;

    #[ORM\Column(length:3)]
    private string $currency = 'MNT';

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
}
