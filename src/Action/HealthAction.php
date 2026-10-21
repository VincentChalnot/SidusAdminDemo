<?php

namespace App\Action;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Liveness probe used by the container HEALTHCHECK.
 *
 * Deliberately touches nothing but the kernel itself: no database, no cache, no session. It answers
 * "is PHP up and able to serve a request through the front controller (worker included)?", not
 * "is the whole stack ready?" — a database outage must not get the container restarted.
 */
class HealthAction
{
    #[Route('/healthz', name: 'app_healthz', methods: ['GET', 'HEAD'])]
    public function __invoke(): Response
    {
        return new Response('ok', Response::HTTP_OK, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
