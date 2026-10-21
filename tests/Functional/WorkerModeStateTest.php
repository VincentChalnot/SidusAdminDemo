<?php

namespace App\Tests\Functional;

use App\Entity\Category;
use App\Entity\News;
use PHPUnit\Framework\Attributes\Group;

/**
 * Simulates FrankenPHP worker mode: the kernel is NOT rebooted between requests (disableReboot()), so every request is
 * served by the very same container, exactly like consecutive requests handled by one worker. Anything that is not reset
 * between requests (a pager, a query handler, a form, a filter state, the Doctrine identity map...) leaks.
 *
 * The expected output of every request is first computed with a fresh kernel for each request (the default behavior of
 * the test client); the same requests are then replayed, in rotating orders and alternating filters, on ONE kernel: every
 * page must be byte-identical. With the previous, stateful sidus/* bundles this failed with "Pager already applied".
 *
 * `vendor/bin/phpunit --group worker-mode` runs only these.
 */
#[Group('worker-mode')]
final class WorkerModeStateTest extends AppWebTestCase
{
    private const ROUNDS = 6;

    public function testOneKernelServesAlternatingRequestsLikeAFreshKernelDoes(): void
    {
        $culture = $this->entityManager()->getRepository(Category::class)->findOneBy(['title' => 'Culture']);
        $requests = [
            'home' => ['/', []],
            'list' => ['/news/', []],
            'filtered-title' => ['/news/', ['filters' => ['title' => 'item 1']]],
            'filtered-title-other' => ['/news/', ['filters' => ['title' => 'item 4', 'publicationStatus' => '']]],
            'filtered-status' => ['/news/', ['filters' => ['publicationStatus' => ['draft']]]],
            'filtered-author' => ['/news/', ['filters' => ['author' => 'bob', 'publicationStatus' => '']]],
            'filtered-date' => ['/news/', ['filters' => ['publicationDate' => ['from' => '2025-02-01', 'to' => '2025-02-10']]]],
            'filtered-category' => ['/news/', ['filters' => ['categories' => [$culture->getId()]]]],
            'sorted' => ['/news/', ['sort' => 'title']],
            'sorted-desc' => ['/news/', ['sort' => '-author']],
            'paged' => ['/news/', ['page' => 2]],
            'all-together' => ['/news/', ['filters' => ['title' => 'News', 'publicationStatus' => ''], 'sort' => 'title', 'page' => 4]],
            'invalid-page' => ['/news/', ['page' => 'abc']],
            'create-form' => ['/news/create', []],
            'read' => ['/news/read/7', []],
        ];

        // 1. The reference: one fresh kernel per request.
        $reference = [];
        foreach ($requests as $name => [$uri, $parameters]) {
            $this->client->request('GET', $uri, $parameters);
            self::assertResponseIsSuccessful($name);
            $reference[$name] = $this->normalizedBody();
        }

        // 2. The same requests on one kernel, in a different order each round.
        $this->client->disableReboot();
        $names = array_keys($requests);
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $order = $names;
            if (0 === $round % 2) {
                $order = array_reverse($order);
            } elseif ($round > 1) {
                $order = array_merge(array_slice($order, $round), array_slice($order, 0, $round));
            }
            foreach ($order as $name) {
                [$uri, $parameters] = $requests[$name];
                $this->client->request('GET', $uri, $parameters);

                $label = sprintf('round %d, "%s" (%s %s)', $round, $name, $uri, json_encode($parameters));
                self::assertResponseIsSuccessful($label);
                self::assertSame($reference[$name], $this->normalizedBody(), $label.' differs from the output of a fresh kernel');
            }
        }
    }

    public function testSortedThenDefaultListDoesNotKeepTheSort(): void
    {
        $this->client->disableReboot();

        $this->client->request('GET', '/news/', ['sort' => 'title']);
        self::assertSame('News item 02', $this->listedTitles()[0]);

        $this->client->request('GET', '/news/');
        self::assertSame('News item 58', $this->listedTitles()[0], 'The default list must not inherit the previous request\'s sort');
    }

    public function testAFilteredRequestDoesNotShowInTheNextOne(): void
    {
        $this->client->disableReboot();

        $this->client->request('GET', '/news/', ['filters' => ['title' => 'item 13']]);
        self::assertSame(['News item 13'], $this->listedTitles());

        $this->client->request('GET', '/news/', ['filters' => ['title' => 'item 27']]);
        self::assertSame(['News item 27'], $this->listedTitles());
        self::assertSame('item 27', $this->client->getCrawler()->filter('input[name="filters[title]"]')->attr('value'));

        $this->client->request('GET', '/news/');
        self::assertCount(15, $this->listedTitles());
        self::assertSame('', (string) $this->client->getCrawler()->filter('input[name="filters[title]"]')->attr('value'));
    }

    public function testWritesAreVisibleToTheNextRequestOnTheSameKernel(): void
    {
        $this->client->disableReboot();

        // Create
        $crawler = $this->client->request('GET', '/news/create');
        $this->client->submit($crawler->filter('form#form_News_create')->form([
            'form_News_create[title]' => 'Written in a worker',
            'form_News_create[content]' => 'Same kernel for every request.',
            'form_News_create[publicationDate]' => '2026-03-14T09:30',
            'form_News_create[publicationStatus]' => 'published',
        ]));
        self::assertResponseRedirects();

        $this->client->request('GET', '/news/', ['filters' => ['title' => 'Written in a worker']]);
        self::assertSame(['Written in a worker'], $this->listedTitles());

        // Edit: the list of the next request shows the new title, not a stale entity of the identity map.
        $id = $this->entityManager()->getRepository(News::class)->findOneBy(['title' => 'Written in a worker'])->getId();
        $crawler = $this->client->request('GET', '/news/edit/'.$id);
        $this->client->submit($crawler->filter('form#form_News_edit')->form(['form_News_edit[title]' => 'Edited in a worker']));
        self::assertResponseRedirects();

        $this->client->request('GET', '/news/', ['filters' => ['title' => 'in a worker']]);
        self::assertSame(['Edited in a worker'], $this->listedTitles());

        // Delete
        $crawler = $this->client->request('GET', '/news/delete/'.$id);
        $this->client->submit($crawler->filter('form#form_News_delete')->form());
        self::assertResponseRedirects();

        $this->client->request('GET', '/news/', ['filters' => ['title' => 'in a worker']]);
        self::assertSame([], array_filter($this->listedTitles(), static fn (string $title): bool => str_contains($title, 'in a worker')));
        $this->client->request('GET', '/news/read/'.$id);
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * The page without what differs from one request to the next (CSRF tokens).
     */
    private function normalizedBody(): string
    {
        return (string) preg_replace('/(name="[^"]*_token\]?"[^>]*value=")[^"]*"/', '$1X"', (string) $this->client->getResponse()->getContent());
    }
}
