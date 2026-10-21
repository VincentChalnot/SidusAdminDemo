<?php

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\Group;

/**
 * Simulates FrankenPHP worker mode: the kernel is NOT rebooted between requests (disableReboot()), so
 * every request is served by the very same container, exactly like consecutive requests handled by one
 * worker. Anything that is not reset between requests (Pager, query handlers, form state, ...) leaks.
 *
 * Excluded from the default run (see phpunit.xml.dist) because the current sidus/* bundles are not
 * worker-safe yet ("Pager already applied", ...). Run it with:
 *
 *     vendor/bin/phpunit --group worker-mode
 *
 * Drop the exclusion once the bundles are immutable.
 */
#[Group('worker-mode')]
final class WorkerModeStateTest extends AppWebTestCase
{
    /** @var list<array{string, array<string, mixed>}> */
    private const REQUESTS = [
        ['/', []],
        ['/news/', []],
        ['/news/', ['form' => ['filters' => ['title' => 'item 1']]]],
        ['/news/', ['form' => ['sortable' => ['1' => '']]]],
        ['/news/', ['page' => 2]],
        ['/news/', ['form' => ['filters' => ['title' => 'News'], 'sortable' => ['1' => '']], 'page' => 4]],
        ['/news/create', []],
    ];

    public function testSameKernelServesRepeatedRequestsIdentically(): void
    {
        $this->client->disableReboot();

        $reference = [];
        for ($round = 1; $round <= 3; ++$round) {
            foreach (self::REQUESTS as $index => [$uri, $parameters]) {
                $this->client->request('GET', $uri, $parameters);

                $label = sprintf('round %d, request #%d (%s %s)', $round, $index, $uri, json_encode($parameters));
                self::assertResponseIsSuccessful($label);

                // CSRF tokens differ per request: compare the page without them.
                $body = preg_replace('/name="([^"]*_token)"([^>]*)value="[^"]*"/', 'name="$1"$2value="X"', (string) $this->client->getResponse()->getContent());
                if (1 === $round) {
                    $reference[$index] = $body;
                } else {
                    self::assertSame($reference[$index], $body, $label.' differs from its first-round output');
                }
            }
        }
    }

    public function testSortedThenDefaultListDoesNotKeepTheSort(): void
    {
        $this->client->disableReboot();

        $this->client->request('GET', '/news/', ['form' => ['sortable' => ['1' => '']]]);
        self::assertSame('News item 01', $this->listedTitles()[0]);

        $this->client->request('GET', '/news/');
        self::assertSame('News item 58', $this->listedTitles()[0], 'Default list must not inherit the previous request\'s sort');
    }
}
