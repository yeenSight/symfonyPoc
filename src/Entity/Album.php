<?php

namespace App\Entity;

use App\Repository\AlbumRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AlbumRepository::class)]
class Album
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 25)]
    private ?string $name = null;

    #[ORM\Column(length: 255)]
    private ?string $description = null;

    /**
     * @var Collection<int, Pictures>
     */
    #[ORM\OneToMany(mappedBy: 'album', targetEntity: Pictures::class)]
    private Collection $pictures;

    public function __construct()
    {
        $this->pictures = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    /**
     * @return Collection<int, Pictures>
     */
    public function getPictures(): Collection
    {
        return $this->pictures;
    }

    /**
     * @return list<Pictures> Pictures sorted from the most recently uploaded
     */
    public function getPicturesNewestFirst(): array
    {
        $pictures = $this->pictures->toArray();
        usort($pictures, static fn (Pictures $a, Pictures $b): int => [$b->getUploadDate(), $b->getId()] <=> [$a->getUploadDate(), $a->getId()]);

        return $pictures;
    }

    public function getLatestPicture(): ?Pictures
    {
        return $this->getPicturesNewestFirst()[0] ?? null;
    }

    public function addPicture(Pictures $picture): static
    {
        if (!$this->pictures->contains($picture)) {
            $this->pictures->add($picture);
            $picture->setAlbum($this);
        }

        return $this;
    }

    public function removePicture(Pictures $picture): static
    {
        if ($this->pictures->removeElement($picture) && $picture->getAlbum() === $this) {
            $picture->setAlbum(null);
        }

        return $this;
    }
}
