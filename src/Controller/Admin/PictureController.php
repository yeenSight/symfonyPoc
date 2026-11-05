<?php

namespace App\Controller\Admin;

use App\Entity\Album;
use App\Entity\Pictures;
use App\Form\PicturesType;
use App\Repository\PicturesRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/pictures', name: 'app_admin_picture_')]
#[IsGranted('IS_AUTHENTICATED')]
final class PictureController extends AbstractController
{
    private const URL_PREFIX = '/uploads/pictures/';
    private const NO_ALBUM_FOLDER = 'other';
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(PicturesRepository $picturesRepository): Response
    {
        return $this->render('admin/pictures/index.html.twig', [
            'pictures' => $picturesRepository->findBy([], ['id' => 'DESC']),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $entityManager,
    ): Response {
        $picture = new Pictures();
        $form = $this->createForm(PicturesType::class, $picture);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $uploadedFile = $form->get('imageFile')->getData();
            if ($uploadedFile === null) {
                throw new \LogicException('A photo is required to create a picture.');
            }

            $picture->setUrl($this->storeFile($uploadedFile, $picture->getAlbum()));

            $entityManager->persist($picture);
            $entityManager->flush();

            $this->addFlash('success', 'Photo added successfully.');

            return $this->redirectToRoute('app_admin_picture_index');
        }

        return $this->render('admin/pictures/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(
        Request $request,
        Pictures $picture,
        EntityManagerInterface $entityManager,
    ): Response {
        if (!$this->isCsrfTokenValid('delete-picture-'.$picture->getId(), $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $this->removeFile($picture->getUrl());

        $entityManager->remove($picture);
        $entityManager->flush();

        $this->addFlash('success', 'Photo deleted successfully.');

        return $this->redirectToRoute('app_admin_picture_index');
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        Pictures $picture,
        EntityManagerInterface $entityManager,
    ): Response {
        $form = $this->createForm(PicturesType::class, $picture, ['require_file' => false]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $uploadedFile = $form->get('imageFile')->getData();
            if ($uploadedFile !== null) {
                $previousUrl = $picture->getUrl();
                $picture->setUrl($this->storeFile($uploadedFile, $picture->getAlbum()));
                $this->removeFile($previousUrl);
            } else {
                $this->moveToAlbumFolder($picture);
            }

            $entityManager->flush();

            $this->addFlash('success', 'Photo updated successfully.');

            return $this->redirectToRoute('app_admin_picture_index');
        }

        return $this->render('admin/pictures/edit.html.twig', [
            'form' => $form,
            'picture' => $picture,
        ]);
    }

    private function storeFile(UploadedFile $uploadedFile, ?Album $album): string
    {
        $extension = $uploadedFile->guessExtension();
        if ($extension === null) {
            throw new \RuntimeException('The uploaded photo type could not be determined.');
        }

        $folder = $this->albumFolder($album);
        $filename = bin2hex(random_bytes(16)).'.'.$extension;
        $uploadDirectory = $this->uploadRoot().'/'.$folder;
        (new Filesystem())->mkdir($uploadDirectory);
        $uploadedFile->move($uploadDirectory, $filename);

        return self::URL_PREFIX.$folder.'/'.$filename;
    }

    private function moveToAlbumFolder(Pictures $picture): void
    {
        $url = $picture->getUrl();
        $currentPath = $this->resolvePath($url);
        if ($currentPath === null) {
            return;
        }

        $folder = $this->albumFolder($picture->getAlbum());
        if (basename(dirname($currentPath)) === $folder) {
            return;
        }

        $targetDirectory = $this->uploadRoot().'/'.$folder;
        (new Filesystem())->mkdir($targetDirectory);
        (new Filesystem())->rename($currentPath, $targetDirectory.'/'.basename($currentPath));
        $picture->setUrl(self::URL_PREFIX.$folder.'/'.basename($currentPath));
    }

    private function removeFile(?string $url): void
    {
        $filePath = $this->resolvePath($url);
        if ($filePath !== null) {
            (new Filesystem())->remove($filePath);
        }
    }

    private function albumFolder(?Album $album): string
    {
        $slug = $album === null ? '' : (new AsciiSlugger())->slug((string) $album->getName())->lower()->toString();

        return $slug !== '' ? $slug : self::NO_ALBUM_FOLDER;
    }

    private function uploadRoot(): string
    {
        return $this->getParameter('kernel.project_dir').'/public/uploads/pictures';
    }

    // Returns the absolute path of an existing uploaded file, or null if the URL is not a managed upload.
    private function resolvePath(?string $url): ?string
    {
        if ($url === null || !str_starts_with($url, self::URL_PREFIX)) {
            return null;
        }

        $relative = substr($url, strlen(self::URL_PREFIX));
        if (str_contains($relative, '..')) {
            return null;
        }

        $path = $this->uploadRoot().'/'.$relative;

        return is_file($path) ? $path : null;
    }
}
