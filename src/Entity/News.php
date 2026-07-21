<?php

namespace App\Entity;

use App\Repository\NewsRepository;
use App\Utils\StringUtils;
use DateTime;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Table(name: 'news')]
#[ORM\Entity(repositoryClass: NewsRepository::class)]
#[UniqueEntity(fields: ['slug'])]
class News
{
    #[ORM\Id]
    #[ORM\Column(name: 'id', type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    protected ?int $id = null;

    #[Assert\NotBlank]
    #[ORM\Column(type: 'string', unique: true, length: 191)]
    protected ?string $slug = null;

    #[Assert\NotBlank]
    #[ORM\Column(type: 'string')]
    protected ?string $title = null;

    #[Assert\NotBlank]
    #[ORM\Column(type: 'text')]
    protected ?string $content = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    protected ?DateTimeInterface $publicationDate = null;

    #[Assert\NotBlank]
    #[Assert\Choice(['draft', 'rejected', 'validated', 'published', 'unpublished'])]
    #[ORM\Column(type: 'string')]
    protected string $publicationStatus = 'draft';

    #[Assert\NotBlank]
    #[ORM\Column(type: 'datetime')]
    protected DateTimeInterface $createdAt;

    #[Assert\NotBlank]
    #[ORM\Column(type: 'datetime')]
    protected DateTimeInterface $updatedAt;

    #[ORM\ManyToOne(targetEntity: Author::class, cascade: ['detach'], fetch: 'EAGER')]
    #[ORM\JoinColumn(nullable: true)]
    protected ?Author $author = null;

    /** @var Collection<int, Category> */
    #[ORM\ManyToMany(targetEntity: Category::class)]
    #[ORM\JoinTable(name: 'news_category')]
    protected Collection $categories;

    #[ORM\Column(type: 'boolean')]
    protected bool $deleted = false;

    public function __construct()
    {
        $this->createdAt = new DateTime();
        $this->updatedAt = new DateTime();
        $this->categories = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): void
    {
        $this->slug = $slug;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
        if (null === $this->slug) {
            $this->slug = StringUtils::slugify($title);
        }
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(string $content): void
    {
        $this->content = $content;
    }

    public function getPublicationDate(): ?DateTimeInterface
    {
        return $this->publicationDate;
    }

    public function setPublicationDate(?DateTimeInterface $publicationDate): void
    {
        $this->publicationDate = $publicationDate;
    }

    public function getPublicationStatus(): string
    {
        return $this->publicationStatus;
    }

    public function setPublicationStatus(string $publicationStatus): void
    {
        $this->publicationStatus = $publicationStatus;
    }

    public function getCreatedAt(): DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(DateTimeInterface $updatedAt): void
    {
        $this->updatedAt = $updatedAt;
    }

    public function getAuthor(): ?Author
    {
        return $this->author;
    }

    public function setAuthor(Author $author): void
    {
        $this->author = $author;
    }

    /** @return Collection<int, Category> */
    public function getCategories(): Collection
    {
        return $this->categories;
    }

    public function addCategory(Category $category): void
    {
        if (!$this->categories->contains($category)) {
            $this->categories[] = $category;
        }
    }

    public function removeCategory(Category $category): void
    {
        $this->categories->removeElement($category);
    }

    public function isDeleted(): bool
    {
        return $this->deleted;
    }

    public function setDeleted(bool $deleted): void
    {
        $this->deleted = $deleted;
    }

    public function __toString(): string
    {
        return (string) $this->getTitle();
    }
}
