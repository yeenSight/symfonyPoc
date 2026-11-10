<?php

namespace App\Controller\Admin;

use App\Entity\Album;
use App\Form\AlbumType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

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

    #[Route('/quick', name: 'quick_create', methods: ['POST'])]
    public function quickCreate(
        Request $request,
        EntityManagerInterface $entityManager,
        ValidatorInterface $validator,
    ): JsonResponse {
        if (!$this->isCsrfTokenValid('quick-album', (string) $request->request->get('_token'))) {
            return new JsonResponse(['errors' => ['name' => 'Invalid CSRF token.']], Response::HTTP_FORBIDDEN);
        }

        $album = (new Album())
            ->setName(trim((string) $request->request->get('name', '')))
            ->setDescription(trim((string) $request->request->get('description', '')));

        $errors = [];
        foreach (['name' => [new Assert\NotBlank(), new Assert\Length(max: 25)], 'description' => [new Assert\NotBlank(), new Assert\Length(max: 255)]] as $field => $constraints) {
            $value = $field === 'name' ? $album->getName() : $album->getDescription();
            $violations = $validator->validate($value, $constraints);
            if (count($violations) > 0) {
                $errors[$field] = $violations[0]->getMessage();
            }
        }
        if ($errors !== []) {
            return new JsonResponse(['errors' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $entityManager->persist($album);
        $entityManager->flush();

        return new JsonResponse(['id' => $album->getId(), 'name' => $album->getName()], Response::HTTP_CREATED);
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
