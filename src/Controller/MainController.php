<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Repository\PicturesRepository;

final class MainController extends AbstractController
{
    #[Route('/', name: 'app_main')]
    public function index(PicturesRepository $picturesRepository): Response
    {
        return $this->render('main/index.html.twig', [
            'controller_name' => 'MainController',
            'pictures' => $picturesRepository->findLatest(3),
        ]);
    }
}
