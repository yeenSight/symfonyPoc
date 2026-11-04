<?php

namespace App\Controller\Admin;

use App\Entity\Album;
use App\Form\AlbumType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/albums', name: 'app_admin_album_')]
#[IsGranted('IS_AUTHENTICATED')]
final class AlbumController extends AbstractController
{
    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $album = new Album();
        $form = $this->createForm(AlbumType::class, $album);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($album);
            $entityManager->flush();
            $this->addFlash('success', 'Album created successfully.');

            return $this->redirectToRoute('app_album_show', ['id' => $album->getId()]);
        }

        return $this->render('admin/albums/form.html.twig', ['form' => $form, 'title' => 'New album']);
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, Album $album, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(AlbumType::class, $album);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', 'Album updated successfully.');

            return $this->redirectToRoute('app_album_show', ['id' => $album->getId()]);
        }

        return $this->render('admin/albums/form.html.twig', ['form' => $form, 'title' => 'Edit album']);
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, Album $album, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('delete-album-'.$album->getId(), $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        // Photos are kept, they just become album-less.
        foreach ($album->getPictures()->toArray() as $picture) {
            $picture->setAlbum(null);
        }

        $entityManager->remove($album);
        $entityManager->flush();
        $this->addFlash('success', 'Album deleted. Its photos were kept.');

        return $this->redirectToRoute('app_pictures');
    }
}
