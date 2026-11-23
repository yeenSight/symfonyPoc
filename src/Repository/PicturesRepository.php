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
        return $this->findBy(['isPublic' => true], ['uploadDate' => 'DESC', 'id' => 'DESC'], $limit);
    }

    public function findFiltered(array $filters): array
    {
        $qb = $this->createQueryBuilder('p');

        if (!empty($filters['album'])) {
            $qb->andWhere('p.album = :album')
                ->setParameter('album', $filters['album']);
        }

        if (!empty($filters['title'])) {
            $qb->andWhere('p.title LIKE :title')
                ->setParameter('title', '%' . $filters['title'] . '%');
        }

        if (!empty($filters['keywords'])) {
            $qb->andWhere('p.metadata LIKE :keywords')
                ->setParameter('keywords', '%' . $filters['keywords'] . '%');
        }

        if (!empty($filters['dateType'])) {
            if ($filters['dateType'] === 'exact' && !empty($filters['dateExact'])) {
                $qb->andWhere('p.uploadDate = :dateExact')
                    ->setParameter('dateExact', $filters['dateExact']);
            } elseif ($filters['dateType'] === 'range') {
                if (!empty($filters['dateFrom'])) {
                    $qb->andWhere('p.uploadDate >= :dateFrom')
                        ->setParameter('dateFrom', $filters['dateFrom']);
                }
                if (!empty($filters['dateTo'])) {
                    $qb->andWhere('p.uploadDate <= :dateTo')
                        ->setParameter('dateTo', $filters['dateTo']);
                }
            } elseif ($filters['dateType'] === 'after' && !empty($filters['dateAfter'])) {
                $qb->andWhere('p.uploadDate > :dateAfter')
                    ->setParameter('dateAfter', $filters['dateAfter']);
            } elseif ($filters['dateType'] === 'before' && !empty($filters['dateBefore'])) {
                $qb->andWhere('p.uploadDate < :dateBefore')
                    ->setParameter('dateBefore', $filters['dateBefore']);
            }
        }

        $qb->orderBy('p.id', 'DESC');

        return $qb->getQuery()->getResult();
    }

    public function countFiltered(array $filters): int
    {
        $qb = $this->createQueryBuilder('p');
        $qb->select('COUNT(p.id)');

        if (!empty($filters['album'])) {
            $qb->andWhere('p.album = :album')
                ->setParameter('album', $filters['album']);
        }

        if (!empty($filters['title'])) {
            $qb->andWhere('p.title LIKE :title')
                ->setParameter('title', '%' . $filters['title'] . '%');
        }

        if (!empty($filters['keywords'])) {
            $qb->andWhere('p.metadata LIKE :keywords')
                ->setParameter('keywords', '%' . $filters['keywords'] . '%');
        }

        if (!empty($filters['dateType'])) {
            if ($filters['dateType'] === 'exact' && !empty($filters['dateExact'])) {
                $qb->andWhere('p.uploadDate = :dateExact')
                    ->setParameter('dateExact', $filters['dateExact']);
            } elseif ($filters['dateType'] === 'range') {
                if (!empty($filters['dateFrom'])) {
                    $qb->andWhere('p.uploadDate >= :dateFrom')
                        ->setParameter('dateFrom', $filters['dateFrom']);
                }
                if (!empty($filters['dateTo'])) {
                    $qb->andWhere('p.uploadDate <= :dateTo')
                        ->setParameter('dateTo', $filters['dateTo']);
                }
            } elseif ($filters['dateType'] === 'after' && !empty($filters['dateAfter'])) {
                $qb->andWhere('p.uploadDate > :dateAfter')
                    ->setParameter('dateAfter', $filters['dateAfter']);
            } elseif ($filters['dateType'] === 'before' && !empty($filters['dateBefore'])) {
                $qb->andWhere('p.uploadDate < :dateBefore')
                    ->setParameter('dateBefore', $filters['dateBefore']);
            }
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
