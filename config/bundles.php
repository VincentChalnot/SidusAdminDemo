<?php

return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    Symfony\Bundle\SecurityBundle\SecurityBundle::class => ['all' => true],
    Doctrine\Bundle\DoctrineBundle\DoctrineBundle::class => ['all' => true],
    Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle::class => ['all' => true],
    Symfony\Bundle\TwigBundle\TwigBundle::class => ['all' => true],
    Symfony\Bundle\MonologBundle\MonologBundle::class => ['all' => true],

    // Order: the filter bundle, its Doctrine provider, the datagrid built on them, then the admin built on the datagrid.
    Sidus\FilterBundle\SidusFilterBundle::class => ['all' => true],
    Sidus\DoctrineFilterBundle\SidusDoctrineFilterBundle::class => ['all' => true],
    Sidus\DataGridBundle\SidusDataGridBundle::class => ['all' => true],
    Sidus\AdminBundle\SidusAdminBundle::class => ['all' => true],

    Symfony\Bundle\DebugBundle\DebugBundle::class => ['dev' => true, 'test' => true],
    Symfony\Bundle\WebProfilerBundle\WebProfilerBundle::class => ['dev' => true, 'test' => true],
];
