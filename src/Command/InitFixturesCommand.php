<?php

namespace App\Command;

use App\Entity\Author;
use App\Entity\Category;
use App\Entity\News;
use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Faker\Factory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use UnexpectedValueException;
use function count;

/**
 * Load database with test data
 */
class InitFixturesCommand extends Command
{
    /** @var ManagerRegistry */
    protected $doctrine;

    /** @var ValidatorInterface */
    protected $validator;

    /**
     * @param ManagerRegistry    $doctrine
     * @param ValidatorInterface $validator
     */
    public function __construct(ManagerRegistry $doctrine, ValidatorInterface $validator)
    {
        $this->doctrine = $doctrine;
        $this->validator = $validator;
        parent::__construct('app:fixtures:init');
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return int|null|void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $em = $this->doctrine->getManagerForClass(News::class);
        if (!$em instanceof EntityManagerInterface) {
            throw new UnexpectedValueException('No manager found');
        }

        $faker = Factory::create();

        $authors = [];
        for ($i = 0; $i < 70; ++$i) {
            $author = new Author();
            $author->setFirstName($faker->firstName);
            $author->setLastName($faker->lastName);
            $author->setEmail($faker->email);

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
            for ($j = 0; $j < $faker->randomNumber(1); ++$j) {
                $news->addCategory($faker->randomElement($categories));
            }
            $news->setPublicationDate(
                $faker->dateTimeInInterval('-4 years', '+5 years')
            );
            if ($faker->randomNumber(3) > 95) {
                $news->setDeleted(true);
            }

            $errors = $this->validator->validate($news);
            if (0 === count($errors)) {
                $em->persist($news);
            }
        }
        $em->flush();

        $output->writeln('Fixtures load correctly');
    }
}
