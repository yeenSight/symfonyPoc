<?php

namespace App\Controller;

use App\Entity\Album;
use App\Repository\AlbumRepository;
use App\Repository\PicturesRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PicturesController extends AbstractController
{
    #[Route('/pictures', name: 'app_pictures', methods: ['GET'])]
    public function index(AlbumRepository $albumRepository, PicturesRepository $picturesRepository): Response
    {
        return $this->render('pictures/index.html.twig', [
            'albums' => array_map(
                fn (Album $album) => ['album' => $album, 'pictures' => $picturesRepository->findBy(['album' => $album, 'isPublic' => true], ['uploadDate' => 'DESC', 'id' => 'DESC'])],
                $albumRepository->findBy([], ['name' => 'ASC']),
            ),
            'others' => $this->findOthers($picturesRepository),
        ]);
    }

    #[Route('/albums/others', name: 'app_album_others', methods: ['GET'])]
    public function others(PicturesRepository $picturesRepository): Response
    {
        return $this->render('pictures/album.html.twig', [
            'album' => null,
            'name' => 'Others',
            'description' => 'Photos that are not in any album.',
            'pictures' => $this->findOthers($picturesRepository),
        ]);
    }

    #[Route('/albums/{id}', name: 'app_album_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Album $album, PicturesRepository $picturesRepository): Response
    {
        return $this->render('pictures/album.html.twig', [
            'album' => $album,
            'name' => $album->getName(),
            'description' => $album->getDescription(),
            'pictures' => $picturesRepository->findBy(['album' => $album, 'isPublic' => true], ['uploadDate' => 'DESC', 'id' => 'DESC']),
        ]);
    }

    /**
     * @return list<\App\Entity\Pictures>
     */
    private function findOthers(PicturesRepository $picturesRepository): array
    {
        return $picturesRepository->findBy(['album' => null, 'isPublic' => true], ['uploadDate' => 'DESC', 'id' => 'DESC']);
    }
}
