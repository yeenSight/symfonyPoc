<?php

namespace App\Entity;

use App\Repository\StatisticRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: StatisticRepository::class)]
class Statistic
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private ?int $picturesCount = null;

    #[ORM\Column]
    private ?int $albumCount = null;

    #[ORM\Column]
    private ?int $userCount = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPicturesCount(): ?int
    {
        return $this->picturesCount;
    }

    public function setPicturesCount(int $picturesCount): static
    {
        $this->picturesCount = $picturesCount;

        return $this;
    }

    public function getAlbumCount(): ?int
    {
        return $this->albumCount;
    }

    public function setAlbumCount(int $albumCount): static
    {
        $this->albumCount = $albumCount;

        return $this;
    }

    public function getUserCount(): ?int
    {
        return $this->userCount;
    }

    public function setUserCount(int $userCount): static
    {
        $this->userCount = $userCount;

        return $this;
    }
}
