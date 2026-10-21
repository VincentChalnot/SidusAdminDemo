<?php

namespace App\Tests\Functional;

use App\Entity\Author;
use App\Entity\Category;
use App\Entity\News;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Base class: boots the app in the "test" environment and rebuilds the database (SQLite by default,
 * see phpunit.xml.dist) with a small, fully deterministic data set before every test.
 *
 * Data set (so assertions can use exact numbers):
 *  - 60 News "News item 01" .. "News item 60"; status cycles through draft, rejected, validated,
 *    published, unpublished according to the index modulo 5 (0 => draft ... 4 => unpublished);
 *  - News 03 and 12 are soft-deleted (deleted = true), hence hidden by the datagrid's default filter;
 *  - with the datagrid's default filters (validated + published, not deleted) that leaves 22 results,
 *    i.e. 2 pages of 15 + 7.
 */
abstract class AppWebTestCase extends WebTestCase
{
    protected const STATUSES = ['draft', 'rejected', 'validated', 'published', 'unpublished'];
    protected const NEWS_COUNT = 60;
    protected const DELETED_NEWS = [3, 12];

    protected KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $this->resetDatabase();
    }

    protected function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }

    /**
     * Texts of the "Title" cells of the datagrid, in display order.
     *
     * @return list<string>
     */
    protected function listedTitles(): array
    {
        return $this->client->getCrawler()->filter('table.table tbody tr td:nth-child(2)')->each(
            static fn ($cell): string => trim($cell->text())
        );
    }

    private function resetDatabase(): void
    {
        $entityManager = $this->entityManager();
        $schemaTool = new SchemaTool($entityManager);
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        $authors = [];
        foreach ([['Alice', 'Martin'], ['Bob', 'Durand'], ['Carol', 'Petit']] as [$firstName, $lastName]) {
            $author = new Author();
            $author->setFirstName($firstName);
            $author->setLastName($lastName);
            $author->setEmail(strtolower($firstName).'@example.test');
            $entityManager->persist($author);
            $authors[] = $author;
        }

        $categories = [];
        foreach (['Tech', 'Sport', 'Culture', 'Politics'] as $title) {
            $category = new Category();
            $category->setTitle($title);
            $entityManager->persist($category);
            $categories[] = $category;
        }

        $firstDay = new DateTime('2025-01-01 12:00:00');
        for ($i = 1; $i <= self::NEWS_COUNT; ++$i) {
            $news = new News();
            $news->setTitle(sprintf('News item %02d', $i));
            $news->setContent(sprintf('Content of news item %02d.', $i));
            $news->setPublicationStatus(self::STATUSES[$i % 5]);
            $news->setPublicationDate((clone $firstDay)->modify(sprintf('+%d days', $i)));
            $news->setAuthor($authors[$i % 3]);
            $news->addCategory($categories[$i % 4]);
            $news->setDeleted(in_array($i, self::DELETED_NEWS, true));
            $entityManager->persist($news);
        }

        $entityManager->flush();
        $entityManager->clear();
    }
}
