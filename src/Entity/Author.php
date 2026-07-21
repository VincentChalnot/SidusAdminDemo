<?php

namespace App\Entity;

use App\Repository\AuthorRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Table(name: 'author')]
#[ORM\Entity(repositoryClass: AuthorRepository::class)]
class Author
{
    #[ORM\Id]
    #[ORM\Column(name: 'id', type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    protected ?int $id = null;

    #[Assert\NotBlank]
    #[ORM\Column(type: 'string')]
    protected string $fullName = '';

    #[Assert\NotBlank]
    #[ORM\Column(type: 'string')]
    protected string $firstName = '';

    #[Assert\NotBlank]
    #[ORM\Column(type: 'string')]
    protected string $lastName = '';

    #[Assert\NotBlank]
    #[Assert\Email]
    #[ORM\Column(type: 'string')]
    protected string $email = '';

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFullName(): string
    {
        return $this->fullName;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): void
    {
        $this->firstName = $firstName;
        $this->updateFullName();
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): void
    {
        $this->lastName = $lastName;
        $this->updateFullName();
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): void
    {
        $this->email = $email;
    }

    public function __toString(): string
    {
        return $this->getFullName();
    }

    protected function updateFullName(): void
    {
        $this->fullName = trim($this->firstName.' '.$this->lastName);
    }
}
