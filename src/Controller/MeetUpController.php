<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Repository\MeetRepository;

final class MeetUpController extends AbstractController
{
    #[Route('/meetup', name: 'app_meet_up')]
    public function index(MeetRepository $repository): Response
    {
        $meets = $repository->findAll();

        return $this->render('meet_up/index.html.twig', [
            'controller_name' => 'MeetUpController',
            'meets' => $meets,
        ]);
    }
}
