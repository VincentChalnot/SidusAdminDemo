<?php

namespace App\Tests\Functional;

final class HomeTest extends AppWebTestCase
{
    public function testHomePage(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('nav.navbar');
        self::assertSelectorExists('a.navbar-item[href="/news/"]');
        // Bulma is served by AssetMapper (importmap-managed package), before the app overrides.
        self::assertSelectorExists('link[rel="stylesheet"][href*="/assets/vendor/bulma/css/bulma.min"]');
        self::assertSelectorExists('link[rel="stylesheet"][href*="/assets/styles/app"]');
    }

    public function testHealthzIsAliveAndDoesNotNeedTheDatabase(): void
    {
        // Drop every table: /healthz must still answer, it never touches the database.
        $entityManager = $this->entityManager();
        $entityManager->getConnection()->executeStatement('DROP TABLE news_category');
        $entityManager->getConnection()->executeStatement('DROP TABLE news');

        $this->client->request('GET', '/healthz');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('ok', $this->client->getResponse()->getContent());
        self::assertResponseHeaderSame('Content-Type', 'text/plain; charset=UTF-8');
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    public function testHealthzAcceptsHead(): void
    {
        $this->client->request('HEAD', '/healthz');

        self::assertResponseStatusCodeSame(200);
    }

    public function testUnknownRouteIsA404(): void
    {
        $this->client->request('GET', '/does-not-exist');

        self::assertResponseStatusCodeSame(404);
    }
}
