<?php

namespace App\Controller\Admin;

use App\Entity\Album;
use App\Entity\DispatchCatalog;
use App\Entity\Pictures;
use App\Form\PicturesType;
use App\Repository\PicturesRepository;
use App\Service\ImageResizer;
use App\Service\PictureStorage;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/admin/pictures', name: 'app_admin_picture_')]
#[IsGranted('IS_AUTHENTICATED')]
final class PictureController extends AbstractController
{
    private const URL_PREFIX = '/uploads/pictures/';
    private const NO_ALBUM_FOLDER = 'other';

    public function __construct(private readonly ImageResizer $imageResizer, private readonly PictureStorage $pictureStorage)
    {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, PicturesRepository $picturesRepository, EntityManagerInterface $entityManager): Response
    {
        $filters = $this->extractFilters($request);
        
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = 25;
        $offset = ($page - 1) * $limit;

        $totalPictures = $picturesRepository->countFiltered($filters);
        $pictures = $picturesRepository->findFiltered($filters);
        $pictures = array_slice($pictures, $offset, $limit);
        $totalPages = (int) ceil($totalPictures / $limit);

        $albums = $entityManager->getRepository(Album::class)->findBy([], ['name' => 'ASC']);

        return $this->render('admin/pictures/index.html.twig', [
            'pictures' => $pictures,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalPictures' => $totalPictures,
            'albums' => $albums,
            'filters' => $filters,
        ]);
    }

    private function extractFilters(Request $request): array
    {
        return [
            'album' => $request->query->get('album') ? (int) $request->query->get('album') : null,
            'title' => $request->query->get('title') ?: null,
            'keywords' => $request->query->get('keywords') ?: null,
            'dateType' => $request->query->get('dateType') ?: null,
            'dateExact' => $request->query->get('dateExact') ? new \DateTime($request->query->get('dateExact')) : null,
            'dateFrom' => $request->query->get('dateFrom') ? new \DateTime($request->query->get('dateFrom')) : null,
            'dateTo' => $request->query->get('dateTo') ? new \DateTime($request->query->get('dateTo')) : null,
            'dateAfter' => $request->query->get('dateAfter') ? new \DateTime($request->query->get('dateAfter')) : null,
            'dateBefore' => $request->query->get('dateBefore') ? new \DateTime($request->query->get('dateBefore')) : null,
        ];
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
            $picture->setIsPublic(true);

            $entityManager->persist($picture);
            $entityManager->flush();

            $this->addFlash('success', 'Photo added successfully.');

            return $this->redirectToRoute('app_admin_picture_index');
        }

        return $this->render('admin/pictures/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/addDispatch', name: 'dispatch', methods: ['POST'])]
    public function addDispatch(
        Request $request,
        EntityManagerInterface $entityManager,
        ValidatorInterface $validator,
    ): JsonResponse {
        return $this->uploadPicture($request, $entityManager, $validator, true);
    }

    #[Route('/bulk/dispatch', name: 'bulk_dispatch', methods: ['GET'])]
    public function bulkDispatch(EntityManagerInterface $entityManager): Response
    {
        return $this->render('admin/pictures/bulk.html.twig', [
            'albums' => $entityManager->getRepository(Album::class)->findBy([], ['name' => 'ASC']),
            'dispatches' => $entityManager->getRepository(DispatchCatalog::class)->findBy([], ['nom' => 'ASC']),
        ]);
    }

    #[Route('/bulk/dispatch/create', name: 'dispatch_create', methods: ['POST'])]
    public function createDispatch(Request $request, EntityManagerInterface $entityManager, ValidatorInterface $validator): JsonResponse
    {
        if (!$this->isCsrfTokenValid('dispatch-create', (string) $request->request->get('_token'))) {
            return new JsonResponse(['errors' => ['name' => 'Invalid CSRF token.']], Response::HTTP_FORBIDDEN);
        }

        $name = trim((string) $request->request->get('name', ''));
        $violations = $validator->validate($name, [new Assert\NotBlank(), new Assert\Length(max: 255)]);
        if (count($violations) > 0) {
            return new JsonResponse(['errors' => ['name' => $violations[0]->getMessage()]], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $idCode = null;
        for ($attempt = 0; $attempt < 25; ++$attempt) {
            $idCode = sprintf('%06d', random_int(0, 999999));
            if ($entityManager->getRepository(DispatchCatalog::class)->findOneBy(['idCode' => $idCode]) === null) {
                break;
            }
            $idCode = null;
        }
        if ($idCode === null) {
            return new JsonResponse(['errors' => ['name' => 'Could not generate an available identifier. Please retry.']], Response::HTTP_CONFLICT);
        }
        do {
            $code = sprintf('%06d', random_int(0, 999999));
        } while ($code === $idCode);

        $dispatch = (new DispatchCatalog())->setNom($name)->setIdCode($idCode)->setCode(password_hash($code, PASSWORD_DEFAULT));
        $entityManager->persist($dispatch);
        try {
            $entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            return new JsonResponse(['errors' => ['name' => 'Identifier collision. Please retry.']], Response::HTTP_CONFLICT);
        }

        return new JsonResponse(['id' => $dispatch->getId(), 'name' => $name, 'idCode' => $idCode, 'code' => $code], Response::HTTP_CREATED, ['Cache-Control' => 'no-store']);
    }

    #[Route('/bulk/dispatch/{id}/code', name: 'dispatch_reset_code', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function resetDispatchCode(Request $request, DispatchCatalog $dispatch, EntityManagerInterface $entityManager): JsonResponse
    {
        if (!$this->isCsrfTokenValid('dispatch-reset', (string) $request->request->get('_token'))) {
            return new JsonResponse(['errors' => ['code' => 'Invalid CSRF token.']], Response::HTTP_FORBIDDEN);
        }

        $code = $entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($dispatch): string {
            $entityManager->lock($dispatch, LockMode::PESSIMISTIC_WRITE);
            $entityManager->refresh($dispatch);
            do {
                $code = sprintf('%06d', random_int(0, 999999));
            } while ($code === $dispatch->getIdCode() || password_verify($code, $dispatch->getCode()));

            $dispatch->setCode(password_hash($code, PASSWORD_DEFAULT))
                ->setFailedAttempts(0)
                ->setLockedUntil(null);
            return $code;
        });

        return new JsonResponse(['idCode' => $dispatch->getIdCode(), 'code' => $code], Response::HTTP_OK, ['Cache-Control' => 'no-store']);
    }

    #[Route('/bulk', name: 'bulk', methods: ['GET'])]
    public function bulk(EntityManagerInterface $entityManager): Response
    {
        return $this->render('admin/pictures/bulk.html.twig', [
            'albums' => $entityManager->getRepository(Album::class)->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route('/bulk/upload', name: 'bulk_upload', methods: ['POST'])]
    public function bulkUpload(
        Request $request,
        EntityManagerInterface $entityManager,
        ValidatorInterface $validator,
    ): JsonResponse {
        return $this->uploadPicture($request, $entityManager, $validator, false);
    }

    private function uploadPicture(Request $request, EntityManagerInterface $entityManager, ValidatorInterface $validator, bool $isDispatch): JsonResponse
    {
        if (!$this->isCsrfTokenValid('bulk-pictures', (string) $request->request->get('_token'))) {
            return new JsonResponse(['errors' => ['file' => 'Invalid CSRF token.']], Response::HTTP_FORBIDDEN);
        }

        $data = [
            'title' => (string) $request->request->get('title', ''),
            'description' => (string) $request->request->get('description', ''),
            'metadata' => (string) $request->request->get('metadata', ''),
            'uploadDate' => (string) $request->request->get('uploadDate', ''),
        ];
        $file = $request->files->get('file');
        $albumId = $request->request->get('album');
        $album = $albumId ? $entityManager->getRepository(Album::class)->find($albumId) : null;
        $dispatchId = $request->request->get('dispatch');
        $dispatch = $isDispatch && $dispatchId ? $entityManager->getRepository(DispatchCatalog::class)->find($dispatchId) : null;

        $fieldConstraints = [
            'title' => [new Assert\NotBlank(), new Assert\Length(max: 25)],
            'description' => [new Assert\Length(max: 255)],
            'metadata' => [new Assert\Length(max: 255)],
            'uploadDate' => [new Assert\NotBlank(), new Assert\Date()],
        ];
        $errors = [];
        foreach ($fieldConstraints as $field => $constraints) {
            $violations = $validator->validate($data[$field], $constraints);
            if (count($violations) > 0) {
                $errors[$field] = $violations[0]->getMessage();
            }
        }
        $violations = $validator->validate($file, [
            new Assert\NotNull(),
            new Assert\Image(mimeTypes: ['image/jpeg', 'image/png', 'image/gif', 'image/webp']),
        ]);
        if (count($violations) > 0) {
            $errors['file'] = $violations[0]->getMessage();
        }
        if ($albumId && $album === null) {
            $errors['album'] = 'This album does not exist.';
        }
        if ($isDispatch && $dispatch === null) {
            $errors['dispatch'] = 'Select a valid dispatch.';
        }
        if ($errors !== []) {
            return new JsonResponse(['errors' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $picture = (new Pictures())
            ->setTitle($data['title'])
            ->setDescription($data['description'])
            ->setMetadata($data['metadata'])
            ->setUploadDate(new \DateTime($data['uploadDate']))
            ->setAlbum($album)
            ->setDispatchCatalog($dispatch)
            ->setIsPublic(!$isDispatch);
        $picture->setUrl($this->storeFile($file, $album, $isDispatch));

        $entityManager->persist($picture);
        try {
            $entityManager->flush();
        } catch (\Throwable $exception) {
            $this->removeFile($picture->getUrl());
            throw $exception;
        }

        return new JsonResponse(['id' => $picture->getId(), 'url' => $picture->getUrl()], Response::HTTP_CREATED);
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
                $picture->setUrl($this->storeFile($uploadedFile, $picture->getAlbum(), !$picture->getIsPublic()));
                $this->removeFile($previousUrl);
            } else {
                if ($picture->getIsPublic()) {
                    $this->moveToAlbumFolder($picture);
                }
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

    private function storeFile(UploadedFile $uploadedFile, ?Album $album, bool $private = false): string
    {
        $extension = $uploadedFile->guessExtension();
        if ($extension === null) {
            throw new \RuntimeException('The uploaded photo type could not be determined.');
        }

        $folder = $this->albumFolder($album);
        $filename = bin2hex(random_bytes(16)).'.'.$extension;
        $uploadDirectory = $private ? $this->getParameter('kernel.project_dir').'/var/private/pictures' : $this->uploadRoot().'/'.$folder;
        (new Filesystem())->mkdir($uploadDirectory);
        // $this->imageResizer->resizeAndStore(
        //     $uploadedFile->getPathname(),
        //     $uploadedFile->getMimeType() ?? '',
        //     $uploadDirectory.'/'.$filename,
        // );
        $uploadedFile->move($uploadDirectory, $filename);

        return $private ? '/private/pictures/'.$filename : self::URL_PREFIX.$folder.'/'.$filename;
    }

    private function moveToAlbumFolder(Pictures $picture): void
    {
        $url = $picture->getUrl();
        $currentPath = $this->pictureStorage->resolvePath($url);
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
        $filePath = $this->pictureStorage->resolvePath($url);
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
}
