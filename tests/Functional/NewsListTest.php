<?php

namespace App\Tests\Functional;

use App\Entity\Category;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The News datagrid (config/datagrid/News.yaml): default filters, user filters, sorting, paging, violations.
 *
 * Query shape (Sidus/DataGridBundle 5): ?filters[<name>]=...&sort=[-]<column>&page=N. A filter that is absent from the
 * query takes its default (status: validated + published); an explicitly blank value (filters[publicationStatus]=)
 * clears the default.
 */
final class NewsListTest extends AppWebTestCase
{
    public function testListShowsRows(): void
    {
        $this->client->request('GET', '/news/');

        self::assertResponseIsSuccessful();
        // Default filters: status validated + published, not deleted => 22 results, 15 per page.
        self::assertSame('Displaying 15 of 22 results', $this->resultsLabel());
        self::assertCount(15, $this->listedTitles());
        // Default sort: publication date, newest first. 58 (published) is the newest visible item.
        self::assertSame('News item 58', $this->listedTitles()[0]);
        self::assertSelectorExists('a[href="/news/create"]');
        self::assertSelectorExists('a[href^="/news/read/"]');
        // The soft-deleted ones (3 and 12) and the other statuses are not listed.
        self::assertNotContains('News item 12', $this->allTitlesOfAllPages());
    }

    public function testSecondPage(): void
    {
        $this->client->request('GET', '/news/', ['page' => 2]);

        self::assertResponseIsSuccessful();
        self::assertCount(7, $this->listedTitles());
        self::assertSelectorExists('a.pagination-link.is-current[aria-label="2"]');
    }

    public function testFilterByTitle(): void
    {
        // "item 1" matches "News item 10" .. "News item 19"; the default statuses keep 13, 17 and 18 (12 is soft-deleted).
        $this->client->request('GET', '/news/', ['filters' => ['title' => 'item 1']]);

        self::assertResponseIsSuccessful();
        self::assertSame('Displaying 3 of 3 results', $this->resultsLabel());
        self::assertEqualsCanonicalizing(['News item 13', 'News item 17', 'News item 18'], $this->listedTitles());
    }

    public function testBlankStatusClearsTheDefault(): void
    {
        // All the statuses: 10 .. 19 are 10 items, minus the soft-deleted 12.
        $this->client->request('GET', '/news/', ['filters' => ['title' => 'item 1', 'publicationStatus' => '']]);

        self::assertResponseIsSuccessful();
        self::assertSame('Displaying 9 of 9 results', $this->resultsLabel());
        $titles = $this->listedTitles();
        self::assertNotContains('News item 12', $titles);
        self::assertContains('News item 15', $titles);
        self::assertNotContains('News item 20', $titles);
    }

    public function testFilterByStatusChoice(): void
    {
        // Only "draft" (index % 5 == 0): 5, 10, ..., 60 => 12 items, none deleted.
        $this->client->request('GET', '/news/', ['filters' => ['publicationStatus' => ['draft']]]);

        self::assertResponseIsSuccessful();
        self::assertSame('Displaying 12 of 12 results', $this->resultsLabel());
        self::assertCount(12, $this->listedTitles());
    }

    public function testFilterByAuthor(): void
    {
        // The author filter searches author.fullName and author.email, case-insensitively: "bob" => index % 3 == 1.
        // Every status: 1, 4, ..., 58 => 20 items (the soft-deleted 3 and 12 belong to other authors).
        $this->client->request('GET', '/news/', ['filters' => ['author' => 'bob', 'publicationStatus' => '']]);

        self::assertResponseIsSuccessful();
        self::assertSame('Displaying 15 of 20 results', $this->resultsLabel());

        // Default statuses: validated or published and index % 3 == 1 => 7, 13, 22, 28, 37, 43, 52, 58.
        $this->client->request('GET', '/news/', ['filters' => ['author' => 'durand']]);
        self::assertSame('Displaying 8 of 8 results', $this->resultsLabel());
    }

    public function testFilterByDateRange(): void
    {
        // Publication date = 2025-01-01 12:00 + <index> days: 2025-02-01 .. 2025-02-10 are 31 .. 40, bounds inclusive.
        // Default statuses keep 32, 33, 37 and 38.
        $this->client->request('GET', '/news/', ['filters' => ['publicationDate' => ['from' => '2025-02-01', 'to' => '2025-02-10']]]);

        self::assertResponseIsSuccessful();
        self::assertEqualsCanonicalizing(
            ['News item 32', 'News item 33', 'News item 37', 'News item 38'],
            $this->listedTitles()
        );
    }

    public function testFilterByCategory(): void
    {
        // The "relation" filter on the categories collection: news i has the category (i % 4) of the seed.
        // Culture is the category of 2, 6, 10 ...; default statuses (index % 5 == 2 or 3) keep 2, 18, 22, 38, 42 and 58.
        $culture = $this->entityManager()->getRepository(Category::class)->findOneBy(['title' => 'Culture']);
        $this->client->request('GET', '/news/', ['filters' => ['categories' => [$culture->getId()]]]);

        self::assertResponseIsSuccessful();
        self::assertSame('Displaying 6 of 6 results', $this->resultsLabel());
        self::assertEqualsCanonicalizing(
            ['News item 02', 'News item 18', 'News item 22', 'News item 38', 'News item 42', 'News item 58'],
            $this->listedTitles()
        );
    }

    public function testSortByTitle(): void
    {
        // The default filters still apply: 22 results, ascending by title, 15 on the first page.
        $this->client->request('GET', '/news/', ['sort' => 'title']);

        self::assertResponseIsSuccessful();
        self::assertSame('Displaying 15 of 22 results', $this->resultsLabel());
        $titles = $this->listedTitles();
        self::assertSame('News item 02', $titles[0]);
        self::assertSame('News item 42', $titles[14]);
    }

    public function testSortByTitleDescending(): void
    {
        $this->client->request('GET', '/news/', ['sort' => '-title']);

        self::assertResponseIsSuccessful();
        self::assertSame('News item 58', $this->listedTitles()[0]);
    }

    public function testSortByAuthorFullNameAndByAColumnWithoutFilter(): void
    {
        // "author" is sorted on author.fullName: Alice Martin (index % 3 == 0), Bob Durand (1), Carol Petit (2).
        $this->client->request('GET', '/news/', ['sort' => 'author']);
        self::assertResponseIsSuccessful();
        self::assertSame('Alice Martin', $this->firstAuthorCell());

        $this->client->request('GET', '/news/', ['sort' => '-author']);
        self::assertSame('Carol Petit', $this->firstAuthorCell());

        // "id" has no filter at all, it is sortable on its own: the lowest visible id is 2.
        $this->client->request('GET', '/news/', ['sort' => 'id']);
        self::assertResponseIsSuccessful();
        self::assertSame('2', $this->client->getCrawler()->filter('table.table tbody tr')->first()->filter('td')->first()->text());
    }

    public function testClickingASortButtonSortsThenReverses(): void
    {
        // The header buttons are submit buttons of the filter form, named filters[sort]; a hidden input of the same
        // name carries the current sort and comes first, so the clicked button wins (last value of the query).
        $crawler = $this->client->request('GET', '/news/');
        $this->clickSortButton($crawler->filter('button[name="filters[sort]"][value="title"]'));

        self::assertResponseIsSuccessful();
        self::assertSame('News item 02', $this->listedTitles()[0]);
        self::assertSelectorExists('th[aria-sort="ascending"]');

        // The same header now offers the opposite direction; the filters of the form (the default statuses) are kept.
        $button = $this->client->getCrawler()->filter('th[aria-sort] button');
        self::assertSame('-title', $button->attr('value'));
        $this->clickSortButton($button);
        self::assertSame('News item 58', $this->listedTitles()[0]);
        self::assertSelectorExists('th[aria-sort="descending"]');
        self::assertSame('Displaying 15 of 22 results', $this->resultsLabel());
    }

    public function testFilterSortAndPageTogether(): void
    {
        // Every non-deleted item (58), sorted by title ascending, page 4 => items 48 .. 60 (13 rows).
        $this->client->request('GET', '/news/', [
            'filters' => ['title' => 'News', 'publicationStatus' => ''],
            'sort' => 'title',
            'page' => 4,
        ]);

        self::assertResponseIsSuccessful();
        $titles = $this->listedTitles();
        self::assertCount(13, $titles);
        self::assertSame('News item 48', $titles[0]);
        self::assertSame('News item 60', $titles[12]);
        self::assertSelectorExists('a.pagination-link.is-current[aria-label="4"]');
    }

    public function testPaginationAndSortLinksKeepTheFilters(): void
    {
        $this->client->request('GET', '/news/', [
            'filters' => ['title' => 'News', 'publicationStatus' => ''],
            'sort' => '-title',
            'page' => 2,
        ]);

        $links = $this->client->getCrawler()->filter('a.pagination-link, a.pagination-next, a.pagination-previous')
            ->each(static fn ($link): string => urldecode(html_entity_decode((string) $link->attr('href'))));
        self::assertNotEmpty($links);
        foreach ($links as $href) {
            self::assertStringContainsString('filters[title]=News', $href);
            self::assertStringContainsString('sort=-title', $href);
        }
        // The sort header buttons are part of the filter form: the filters are re-submitted with the sort.
        self::assertSame('News', $this->client->getCrawler()->filter('input[name="filters[title]"]')->attr('value'));
    }

    public function testInvalidInputIsAViolationNotAnError(): void
    {
        foreach ([
            ['page' => 'abc'],
            ['page' => '0'],
            ['page' => '-3'],
            ['sort' => 'nothing'],
            ['filters' => ['publicationDate' => ['from' => 'not-a-date']]],
            ['filters' => ['publicationStatus' => ['bogus']]],
        ] as $query) {
            $this->client->request('GET', '/news/', $query);

            self::assertResponseStatusCodeSame(200, 'Query '.json_encode($query));
            // The invalid part falls back to its default: the list is shown, it is not empty.
            self::assertNotEmpty($this->listedTitles(), 'Query '.json_encode($query));
        }

        // The violation of the page is shown as an error of the form.
        $this->client->request('GET', '/news/', ['page' => 'abc']);
        self::assertSelectorExists('.sidus-datagrid .help.is-danger, .sidus-datagrid .notification.is-danger');
        self::assertSame('Displaying 15 of 22 results', $this->resultsLabel());
    }

    public function testRequestsDoNotLeakStateIntoEachOther(): void
    {
        // Kernel is rebooted between requests (default WebTestCase behavior): a request after a
        // sorted/filtered/paged one must come out exactly like the very first default request.
        $this->client->request('GET', '/news/');
        $reference = $this->client->getResponse()->getContent();

        $this->client->request('GET', '/news/', ['filters' => ['title' => 'item 1'], 'page' => 1]);
        $this->client->request('GET', '/news/', ['sort' => 'title']);
        $this->client->request('GET', '/news/', ['page' => 2]);
        $this->client->request('GET', '/news/');

        self::assertSame($reference, $this->client->getResponse()->getContent());
    }

    /**
     * Submits the filter form as a browser does when a sort button is clicked: the whole form, then the button's own
     * name=value last (Symfony's DomCrawler would let the earlier hidden input of the same name win).
     */
    private function clickSortButton(Crawler $button): void
    {
        $form = $button->form();
        $values = $form->getPhpValues();
        $values['filters']['sort'] = $button->attr('value');

        $this->client->request($form->getMethod(), $form->getUri(), $values);
    }

    private function resultsLabel(): string
    {
        return trim($this->client->getCrawler()->filter('.sidus-datagrid-count')->text());
    }

    private function firstAuthorCell(): string
    {
        return trim($this->client->getCrawler()->filter('table.table tbody tr')->first()->filter('td')->eq(4)->text());
    }

    /**
     * @return list<string>
     */
    private function allTitlesOfAllPages(): array
    {
        $titles = $this->listedTitles();
        $this->client->request('GET', '/news/', ['page' => 2]);

        return array_merge($titles, $this->listedTitles());
    }
}
