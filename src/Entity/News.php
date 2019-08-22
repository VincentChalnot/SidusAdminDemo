<?php

namespace App\Entity;

use App\Utils\StringUtils;
use DateTime;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @ORM\Table(name="news")
 * @ORM\Entity()
 *
 * @UniqueEntity(fields={"slug"})
 */
class News
{
    /**
     * @var int|null
     *
     * @ORM\Id
     * @ORM\Column(name="id", type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    protected $id;

    /**
     * @var string|null
     *
     * @Assert\NotBlank()
     *
     * @ORM\Column(type="string", unique=true, length=191)
     */
    protected $slug;

    /**
     * @var string|null
     *
     * @Assert\NotBlank()
     *
     * @ORM\Column(type="string")
     */
    protected $title;

    /**
     * @var string|null
     *
     * @Assert\NotBlank()
     *
     * @ORM\Column(type="text")
     */
    protected $content;

    /**
     * @var DateTimeInterface|null
     *
     * @ORM\Column(type="datetime", nullable=true)
     */
    protected $publicationDate;

    /**
     * @var string|null
     *
     * @Assert\NotBlank()
     * @Assert\Choice({
     *     "draft",
     *     "rejected",
     *     "validated",
     *     "published",
     *     "unpublished",
     * })
     *
     * @ORM\Column(type="string")
     */
    protected $publicationStatus = 'draft';

    /**
     * @var DateTimeInterface
     *
     * @Assert\NotBlank()
     *
     * @ORM\Column(type="datetime")
     */
    protected $createdAt;

    /**
     * @var DateTimeInterface
     *
     * @Assert\NotBlank()
     *
     * @ORM\Column(type="datetime")
     */
    protected $updatedAt;

    /**
     * @var Author|null
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\Author", cascade={"detach"}, fetch="EAGER")
     * @ORM\JoinColumn(nullable=true)
     */
    protected $author;

    /**
     * @var Category[]|Collection|iterable
     *
     * @ORM\ManyToMany(targetEntity="App\Entity\Category")
     * @ORM\JoinTable(name="news_category")
     */
    protected $categories;

    /**
     * @var bool
     *
     * @ORM\Column(type="boolean")
     */
    protected $deleted = false;

    /**
     * Setting initial dates
     */
    public function __construct()
    {
        $this->createdAt = new DateTime();
        $this->updatedAt = new DateTime();
        $this->categories = new ArrayCollection();
    }

    /**
     * @return int|null
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return string|null
     */
    public function getSlug(): ?string
    {
        return $this->slug;
    }

    /**
     * @param string $slug
     */
    public function setSlug(string $slug): void
    {
        $this->slug = $slug;
    }

    /**
     * @return string|null
     */
    public function getTitle(): ?string
    {
        return $this->title;
    }

    /**
     * @param string $title
     */
    public function setTitle(string $title): void
    {
        $this->title = $title;
        if (null === $this->slug) {
            $this->slug = StringUtils::slugify($title);
        }
    }

    /**
     * @return string|null
     */
    public function getContent(): ?string
    {
        return $this->content;
    }

    /**
     * @param string $content
     */
    public function setContent(string $content): void
    {
        $this->content = $content;
    }

    /**
     * @return DateTimeInterface|null
     */
    public function getPublicationDate(): ?DateTimeInterface
    {
        return $this->publicationDate;
    }

    /**
     * @param DateTimeInterface|null $publicationDate
     */
    public function setPublicationDate(?DateTimeInterface $publicationDate): void
    {
        $this->publicationDate = $publicationDate;
    }

    /**
     * @return string
     */
    public function getPublicationStatus(): string
    {
        return $this->publicationStatus;
    }

    /**
     * @param string $publicationStatus
     */
    public function setPublicationStatus(string $publicationStatus): void
    {
        $this->publicationStatus = $publicationStatus;
    }

    /**
     * @return DateTimeInterface
     */
    public function getCreatedAt(): DateTimeInterface
    {
        return $this->createdAt;
    }

    /**
     * @return DateTimeInterface
     */
    public function getUpdatedAt(): DateTimeInterface
    {
        return $this->updatedAt;
    }

    /**
     * @param DateTimeInterface $updatedAt
     */
    public function setUpdatedAt(DateTimeInterface $updatedAt): void
    {
        $this->updatedAt = $updatedAt;
    }

    /**
     * @return Author|null
     */
    public function getAuthor(): ?Author
    {
        return $this->author;
    }

    /**
     * @param Author $author
     */
    public function setAuthor(Author $author): void
    {
        $this->author = $author;
    }

    /**
     * @return Category[]|Collection|iterable
     */
    public function getCategories(): iterable
    {
        return $this->categories;
    }

    /**
     * @param Category $category
     */
    public function addCategory(Category $category): void
    {
        if (!$this->categories->contains($category)) {
            $this->categories[] = $category;
        }
    }

    /**
     * @param Category $category
     */
    public function removeCategory(Category $category): void
    {
        if ($this->categories->contains($category)) {
            $this->categories->removeElement($category);
        }
    }

    /**
     * @return bool
     */
    public function isDeleted(): bool
    {
        return $this->deleted;
    }

    /**
     * @param bool $deleted
     */
    public function setDeleted(bool $deleted): void
    {
        $this->deleted = $deleted;
    }

    /**
     * @return string
     */
    public function __toString()
    {
        /** @noinspection ReturnNullInspection */

        return (string) $this->getTitle();
    }
}
