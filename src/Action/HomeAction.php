<?php

namespace App\Action;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;

/**
 * Displays homepage
 */
class HomeAction
{
    /**
     * @Template("Action/home.html.twig")
     *
     * @return array
     */
    public function __invoke(): array
    {
        return [];
    }
}
