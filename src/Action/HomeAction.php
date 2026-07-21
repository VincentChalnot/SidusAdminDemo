<?php

namespace App\Action;

use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Displays homepage.
 */
class HomeAction
{
    #[Route('/', name: 'app_home')]
    #[Template('Action/home.html.twig')]
    public function __invoke(): array
    {
        return [];
    }
}
