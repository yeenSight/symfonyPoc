<?php

namespace App\Repository;

use App\Entity\Pictures;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Pictures>
 */
class PicturesRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Pictures::class);
    }

    /**
     * @return list<Pictures>
     */
    public function findLatest(int $limit = 3): array
    {
        return $this->findBy([], ['uploadDate' => 'DESC', 'id' => 'DESC'], $limit);
    }
}
