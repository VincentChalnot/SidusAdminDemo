<?php

namespace App\Command;

use App\Entity\Author;
use App\Entity\Category;
use App\Entity\News;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Faker\Factory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use UnexpectedValueException;

/**
 * Load database with test data.
 */
#[AsCommand(name: 'app:fixtures:init', description: 'Load the database with demo fixtures')]
class InitFixturesCommand extends Command
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly ValidatorInterface $validator,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $em = $this->doctrine->getManagerForClass(News::class);
        if (!$em instanceof EntityManagerInterface) {
            throw new UnexpectedValueException('No manager found');
        }

        if ($em->getRepository(News::class)->count([]) > 0) {
            $output->writeln('Fixtures already loaded, skipping.');

            return Command::SUCCESS;
        }

        $faker = Factory::create();

        $authors = [];
        for ($i = 0; $i < 70; ++$i) {
            $author = new Author();
            $author->setFirstName($faker->firstName());
            $author->setLastName($faker->lastName());
            $author->setEmail($faker->email());

            $errors = $this->validator->validate($author);
            if (0 === count($errors)) {
                $em->persist($author);
                $authors[] = $author;
            }
        }
        $em->flush();

        $categories = [];
        for ($i = 0; $i < 100; ++$i) {
            $category = new Category();
            $category->setTitle(rtrim($faker->text(25), '.'));

            $errors = $this->validator->validate($category);
            if (0 === count($errors)) {
                $em->persist($category);
                $categories[] = $category;
            }
        }
        $em->flush();

        for ($i = 0; $i < 1000; ++$i) {
            $news = new News();
            $news->setTitle($faker->text(70));
            $news->setContent($faker->text());
            $news->setPublicationStatus(
                $faker->randomElement(
                    [
                        'draft',
                        'rejected',
                        'validated',
                        'published',
                        'unpublished',
                    ]
                )
            );
            $news->setAuthor($faker->randomElement($authors));
            for ($j = 0; $j < $faker->randomDigit(); ++$j) {
                $news->addCategory($faker->randomElement($categories));
            }
            $news->setPublicationDate(
                $faker->dateTimeInInterval('-4 years', '+5 years')
            );
            if ($faker->numberBetween(0, 999) > 95) {
                $news->setDeleted(true);
            }

            $errors = $this->validator->validate($news);
            if (0 === count($errors)) {
                $em->persist($news);
            }
        }
        $em->flush();

        $output->writeln('Fixtures load correctly');

        return Command::SUCCESS;
    }
}
