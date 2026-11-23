<?php

namespace App\Controller;

use App\Entity\DispatchCatalog;
use App\Entity\Pictures;
use App\Repository\DispatchCatalogRepository;
use App\Repository\PicturesRepository;
use App\Service\PictureStorage;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;

final class DispatchController extends AbstractController
{
    #[Route('/dispatch/{idCode}', name: 'app_dispatch', requirements: ['idCode' => '\d{6}'], defaults: ['idCode' => ''], methods: ['GET', 'POST'])]
    public function show(string $idCode, Request $request, DispatchCatalogRepository $dispatches, PicturesRepository $pictures, EntityManagerInterface $em): Response
    {
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('dispatch-access', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }
            $idCode = (string) $request->request->get('idCode', '');
            $code = (string) $request->request->get('code', '');
            $dispatch = preg_match('/^\d{6}$/D', $idCode) ? $dispatches->findOneBy(['idCode' => $idCode]) : null;
            if ($dispatch !== null && preg_match('/^\d{6}$/D', $code)) {
                $result = $em->wrapInTransaction(function (EntityManagerInterface $em) use ($dispatch, $code): string {
                    $em->lock($dispatch, LockMode::PESSIMISTIC_WRITE);
                    $em->refresh($dispatch);
                    $now = new \DateTimeImmutable();
                    if ($dispatch->getLockedUntil() !== null && $dispatch->getLockedUntil() > $now) {
                        return 'locked';
                    }
                    if ($dispatch->getLockedUntil() !== null) {
                        $dispatch->setFailedAttempts(0)->setLockedUntil(null);
                    }
                    if (password_verify($code, $dispatch->getCode())) {
                        $dispatch->setFailedAttempts(0);
                        return 'ok';
                    }
                    $attempts = $dispatch->getFailedAttempts() + 1;
                    $dispatch->setFailedAttempts($attempts);
                    if ($attempts >= 5) {
                        $dispatch->setLockedUntil($now->modify('+15 minutes'));
                    }
                    return 'invalid';
                });
                if ($result === 'ok') {
                    $request->getSession()->migrate(true);
                    $request->getSession()->set('dispatch_access_'.$dispatch->getId(), hash('sha256', $dispatch->getCode()));
                    return $this->redirectToRoute('app_dispatch', ['idCode' => $idCode]);
                }
                if ($result === 'locked') {
                    $error = 'Too many attempts. Please try again later.';
                }
            }
            $error ??= 'Invalid identifier or access code.';
        }

        $dispatch = preg_match('/^\d{6}$/D', $idCode) ? $dispatches->findOneBy(['idCode' => $idCode]) : null;
        $allowed = $dispatch !== null && $this->hasDispatchAccess($request, $dispatch);
        $response = $this->render('pictures/dispatch.html.twig', [
            'dispatch' => $allowed ? $dispatch : null,
            'idCode' => $idCode,
            'pictures' => $allowed ? $pictures->findBy(['dispatchCatalog' => $dispatch], ['uploadDate' => 'DESC', 'id' => 'DESC']) : [],
            'error' => $error,
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }

    #[Route('/private/pictures/{filename}', name: 'app_private_picture', requirements: ['filename' => '[a-f0-9]{32}\.[a-z0-9]+'], methods: ['GET'])]
    public function picture(string $filename, Request $request, PicturesRepository $pictures, PictureStorage $storage): Response
    {
        $picture = $pictures->findOneBy(['url' => '/private/pictures/'.$filename]);
        if ($picture === null) {
            throw $this->createNotFoundException();
        }
        $dispatch = $picture->getDispatchCatalog();
        if (!$picture->getIsPublic() && !$this->isGranted('IS_AUTHENTICATED') && ($dispatch === null || !$this->hasDispatchAccess($request, $dispatch))) {
            return new Response('Access denied.', Response::HTTP_FORBIDDEN, ['Cache-Control' => 'private, no-store']);
        }
        $response = new BinaryFileResponse($this->picturePath($picture, $storage));
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        return $response;
    }

    #[Route('/dispatch/{idCode}/pictures/{id}/download', name: 'app_dispatch_download_picture', requirements: ['idCode' => '\d{6}', 'id' => '\d+'], methods: ['GET'])]
    public function downloadPicture(string $idCode, int $id, Request $request, DispatchCatalogRepository $dispatches, PicturesRepository $pictures, PictureStorage $storage): Response
    {
        $dispatch = $this->authorizedDispatch($idCode, $request, $dispatches);
        $picture = $pictures->findOneBy(['id' => $id, 'dispatchCatalog' => $dispatch]);
        if ($picture === null) {
            throw $this->createNotFoundException();
        }

        $path = $this->picturePath($picture, $storage);
        $response = new BinaryFileResponse($path);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $this->downloadName($picture, $path));
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        return $response;
    }

    #[Route('/dispatch/{idCode}/download', name: 'app_dispatch_download_all', requirements: ['idCode' => '\d{6}'], methods: ['GET'])]
    public function downloadAll(string $idCode, Request $request, DispatchCatalogRepository $dispatches, PicturesRepository $pictures, PictureStorage $storage): Response
    {
        $dispatch = $this->authorizedDispatch($idCode, $request, $dispatches);
        $items = $pictures->findBy(['dispatchCatalog' => $dispatch], ['id' => 'ASC']);
        if ($items === []) {
            throw $this->createNotFoundException('The dispatch has no photos.');
        }

        $archivePath = tempnam(sys_get_temp_dir(), 'dispatch-');
        if ($archivePath === false) {
            throw new \RuntimeException('Could not create the archive.');
        }
        $archive = new \ZipArchive();
        $opened = false;
        try {
            if ($archive->open($archivePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Could not open the archive.');
            }
            $opened = true;
            foreach ($items as $picture) {
                $path = $this->picturePath($picture, $storage);
                if (!$archive->addFile($path, $this->downloadName($picture, $path))) {
                    throw new \RuntimeException('Could not add a photo to the archive.');
                }
            }
            $closed = $archive->close();
            $opened = false;
            if (!$closed) {
                throw new \RuntimeException('Could not finish the archive.');
            }
        } catch (\Throwable $exception) {
            if ($opened) {
                $archive->close();
            }
            (new Filesystem())->remove($archivePath);
            throw $exception;
        }

        $response = new BinaryFileResponse($archivePath);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'dispatch-'.$idCode.'.zip');
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->deleteFileAfterSend();
        return $response;
    }

    #[Route('/dispatch/{idCode}/publish', name: 'app_dispatch_publish', requirements: ['idCode' => '\d{6}'], methods: ['POST'])]
    public function publish(string $idCode, Request $request, DispatchCatalogRepository $dispatches, PicturesRepository $pictures, EntityManagerInterface $em): Response
    {
        $dispatch = $this->authorizedDispatch($idCode, $request, $dispatches);
        if (!$this->isCsrfTokenValid('dispatch-publish-'.$dispatch->getId(), (string) $request->request->get('_token'))) {
            throw new HttpException(Response::HTTP_FORBIDDEN, 'Invalid CSRF token.');
        }

        $ids = $request->request->all('pictures');
        foreach ($ids as $id) {
            if (!is_string($id) || filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                throw $this->createNotFoundException('Invalid photo selection.');
            }
        }
        $ids = array_unique($ids);

        $em->wrapInTransaction(function (EntityManagerInterface $em) use ($dispatch, $request, $pictures, $ids): void {
            $em->lock($dispatch, LockMode::PESSIMISTIC_WRITE);
            $em->refresh($dispatch);
            if (!$this->hasDispatchAccess($request, $dispatch)) {
                throw new HttpException(Response::HTTP_FORBIDDEN, 'Dispatch access denied.');
            }
            if ($ids === []) {
                return;
            }
            $selected = $pictures->findBy(['id' => $ids, 'dispatchCatalog' => $dispatch]);
            if (count($selected) !== count($ids)) {
                throw $this->createNotFoundException('Invalid photo selection.');
            }
            foreach ($selected as $picture) {
                $picture->setIsPublic(true);
            }
        });

        return $this->redirectToRoute('app_main');
    }

    private function authorizedDispatch(string $idCode, Request $request, DispatchCatalogRepository $dispatches): DispatchCatalog
    {
        $dispatch = $dispatches->findOneBy(['idCode' => $idCode]);
        if ($dispatch === null || !$this->hasDispatchAccess($request, $dispatch)) {
            throw new HttpException(Response::HTTP_FORBIDDEN, 'Dispatch access denied.');
        }

        return $dispatch;
    }

    private function picturePath(Pictures $picture, PictureStorage $storage): string
    {
        $path = $storage->resolvePath($picture->getUrl());
        if ($path === null) {
            throw $this->createNotFoundException('Photo file not found.');
        }

        return $path;
    }

    private function downloadName(Pictures $picture, string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!preg_match('/^[a-z0-9]+$/D', $extension)) {
            throw new \RuntimeException('Invalid photo file extension.');
        }

        return 'photo-'.$picture->getId().'.'.$extension;
    }

    private function hasDispatchAccess(Request $request, DispatchCatalog $dispatch): bool
    {
        $grantedHash = $request->getSession()->get('dispatch_access_'.$dispatch->getId());

        return is_string($grantedHash) && hash_equals(hash('sha256', $dispatch->getCode()), $grantedHash);
    }
}
