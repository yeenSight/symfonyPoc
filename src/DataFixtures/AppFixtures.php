<?php

namespace App\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use App\Factory\MeetFactory;

class AppFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        MeetFactory::createMany(10);
    }
}
