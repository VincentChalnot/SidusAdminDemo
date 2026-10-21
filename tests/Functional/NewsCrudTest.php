<?php

namespace App\Tests\Functional;

use App\Entity\News;

/**
 * Create / read / edit / delete through the Sidus/AdminBundle actions (config/admin/News.yaml).
 */
final class NewsCrudTest extends AppWebTestCase
{
    public function testRead(): void
    {
        $news = $this->findNews('News item 07');

        $this->client->request('GET', '/news/read/'.$news->getId());

        self::assertResponseIsSuccessful();
        // The read action renders the entity in a (disabled) form: values live in field attributes.
        self::assertSelectorExists('input[value="News item 07"]');
        self::assertSelectorTextContains('textarea', 'Content of news item 07.');
    }

    public function testCreate(): void
    {
        $crawler = $this->client->request('GET', '/news/create');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form#form_News_create')->form([
            'form_News_create[title]' => 'A brand new article',
            'form_News_create[content]' => 'Written by the functional test suite.',
            'form_News_create[publicationDate]' => '2026-03-14T09:30',
            'form_News_create[publicationStatus]' => 'published',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();

        $created = $this->findNews('A brand new article');
        self::assertSame('published', $created->getPublicationStatus());
        self::assertSame('a_brand_new_article', $created->getSlug());
        self::assertSame('2026-03-14 09:30', $created->getPublicationDate()?->format('Y-m-d H:i'));
        self::assertSame(self::NEWS_COUNT + 1, $this->entityManager()->getRepository(News::class)->count([]));
    }

    public function testCreateWithEmptyTitleIsRejected(): void
    {
        $crawler = $this->client->request('GET', '/news/create');
        $form = $crawler->filter('form#form_News_create')->form([
            'form_News_create[title]' => '',
            'form_News_create[content]' => 'No title.',
            'form_News_create[publicationDate]' => '2026-03-14T09:30',
            'form_News_create[publicationStatus]' => 'draft',
        ]);
        $this->client->submit($form);

        // Re-displayed with a validation error, nothing persisted.
        self::assertResponseStatusCodeSame(200);
        self::assertSame(self::NEWS_COUNT, $this->entityManager()->getRepository(News::class)->count([]));
    }

    public function testEdit(): void
    {
        $news = $this->findNews('News item 07');

        $crawler = $this->client->request('GET', '/news/edit/'.$news->getId());
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form#form_News_edit')->form([
            'form_News_edit[title]' => 'News item 07 (edited)',
            'form_News_edit[publicationStatus]' => 'unpublished',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();

        $this->entityManager()->clear();
        $edited = $this->entityManager()->getRepository(News::class)->find($news->getId());
        self::assertSame('News item 07 (edited)', $edited->getTitle());
        self::assertSame('unpublished', $edited->getPublicationStatus());
        // The slug field is disabled in the form: it never changes.
        self::assertSame('news_item_07', $edited->getSlug());
    }

    public function testDelete(): void
    {
        $news = $this->findNews('News item 07');
        $id = $news->getId();

        $crawler = $this->client->request('GET', '/news/delete/'.$id);
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->filter('form#form_News_delete')->form());

        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();

        $this->entityManager()->clear();
        self::assertNull($this->entityManager()->getRepository(News::class)->find($id));
        self::assertSame(self::NEWS_COUNT - 1, $this->entityManager()->getRepository(News::class)->count([]));
    }

    public function testReadingAnUnknownNewsIsA404(): void
    {
        $this->client->request('GET', '/news/read/999999');

        self::assertResponseStatusCodeSame(404);
    }

    private function findNews(string $title): News
    {
        $news = $this->entityManager()->getRepository(News::class)->findOneBy(['title' => $title]);
        self::assertInstanceOf(News::class, $news, sprintf('Fixture "%s" not found', $title));

        return $news;
    }
}
