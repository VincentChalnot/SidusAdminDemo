<?php

namespace App\Tests\Functional;

/**
 * The News datagrid (config/datagrid/News.yaml): default filters, user filters, sorting and paging.
 */
final class NewsListTest extends AppWebTestCase
{
    public function testListShowsRows(): void
    {
        $this->client->request('GET', '/news/');

        self::assertResponseIsSuccessful();
        // Default filters: status validated + published, not deleted => 22 results, 15 per page.
        self::assertStringContainsString('Displaying 15 of 22 results', $this->client->getResponse()->getContent());
        self::assertCount(15, $this->listedTitles());
        // Default sort: publication date, newest first. 58 (published) is the newest visible item.
        self::assertSame('News item 58', $this->listedTitles()[0]);
        self::assertSelectorExists('a[href="/news/create"]');
        self::assertSelectorExists('a[href^="/news/read/"]');
    }

    public function testSecondPage(): void
    {
        $this->client->request('GET', '/news/', ['page' => 2]);

        self::assertResponseIsSuccessful();
        self::assertCount(7, $this->listedTitles());
        self::assertSelectorExists('a.pagination-link.is-current[aria-label="Page 2"]');
    }

    public function testFilterByTitle(): void
    {
        // "item 1" matches "News item 10" .. "News item 19"; 12 is soft-deleted => 9 results.
        $this->client->request('GET', '/news/', ['form' => ['filters' => ['title' => 'item 1']]]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Displaying 9 of 9 results', $this->client->getResponse()->getContent());
        $titles = $this->listedTitles();
        self::assertCount(9, $titles);
        self::assertNotContains('News item 12', $titles);
        self::assertContains('News item 15', $titles);
        self::assertNotContains('News item 20', $titles);
    }

    public function testFilterByStatusChoice(): void
    {
        // Only "draft" (index % 5 == 0): 10, 15, ..., 60 and 5 => 12 items, none deleted.
        $this->client->request('GET', '/news/', ['form' => ['filters' => ['publicationStatus' => ['draft']]]]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Displaying 12 of 12 results', $this->client->getResponse()->getContent());
        self::assertCount(12, $this->listedTitles());
    }

    public function testFilterByAuthor(): void
    {
        // The author filter searches author.fullName and author.email: "Bob" => index % 3 == 1.
        $this->client->request('GET', '/news/', ['form' => ['filters' => ['author' => 'Bob']]]);

        self::assertResponseIsSuccessful();
        // 20 items (1, 4, ..., 58) minus none deleted (3 and 12 belong to other authors).
        self::assertStringContainsString('Displaying 15 of 20 results', $this->client->getResponse()->getContent());
    }

    public function testSortByTitle(): void
    {
        // Clicking a sort button submits form[sortable][<index of the column in the "sortable" list>].
        // Index 1 is "title"; no filter submitted => all non-deleted items (58).
        $this->client->request('GET', '/news/', ['form' => ['sortable' => ['1' => '']]]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Displaying 15 of 58 results', $this->client->getResponse()->getContent());
        self::assertSame('News item 01', $this->listedTitles()[0]);
        self::assertSame('News item 17', $this->listedTitles()[14]);
    }

    public function testSortByTitleDescending(): void
    {
        // Clicking the already-active column toggles the direction (state travels in hidden fields).
        $this->client->request('GET', '/news/', ['form' => ['sortable' => [
            'config' => ['column' => 'title', 'direction' => '0', 'page' => '1'],
            '1' => '',
        ]]]);

        self::assertResponseIsSuccessful();
        self::assertSame('News item 60', $this->listedTitles()[0]);
    }

    public function testFilterSortAndPageTogether(): void
    {
        // Every non-deleted item whose title contains "News": 58 results, sorted by title ascending,
        // page 4 => items 46 .. 58 (13 rows, the last page holds 58 - 3 * 15 = 13).
        $this->client->request('GET', '/news/', [
            'form' => [
                'filters' => ['title' => 'News'],
                'sortable' => ['1' => ''],
            ],
            'page' => 4,
        ]);

        self::assertResponseIsSuccessful();
        $titles = $this->listedTitles();
        self::assertCount(13, $titles);
        self::assertSame('News item 48', $titles[0]);
        self::assertSame('News item 60', $titles[12]);
        self::assertSelectorExists('a.pagination-link.is-current[aria-label="Page 4"]');
    }

    public function testRequestsDoNotLeakStateIntoEachOther(): void
    {
        // Kernel is rebooted between requests (default WebTestCase behavior): a request after a
        // sorted/filtered/paged one must come out exactly like the very first default request.
        $this->client->request('GET', '/news/');
        $reference = $this->client->getResponse()->getContent();

        $this->client->request('GET', '/news/', ['form' => ['filters' => ['title' => 'item 1']], 'page' => 1]);
        $this->client->request('GET', '/news/', ['form' => ['sortable' => ['1' => '']]]);
        $this->client->request('GET', '/news/', ['page' => 2]);
        $this->client->request('GET', '/news/');

        self::assertSame($reference, $this->client->getResponse()->getContent());
    }
}
