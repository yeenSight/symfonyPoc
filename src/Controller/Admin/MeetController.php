<?php

namespace App\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use App\Entity\Meet;
use App\Form\NewMeetType;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/admin/meet', name: 'app_admin_meet_')]
class MeetController extends AbstractController
{
    #[Route('', name: 'index')]
    public function index(): Response
    {
        return $this->render('admin/meet/index.html.twig', [
            'controller_name' => 'Admin/MeetController',
        ]);
    }

    #[Route('/new', name: 'new')]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $meet = new Meet();
        $form = $this->createForm(NewMeetType::class, $meet);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Handle the form submission, e.g., save the $meet entity
            $entityManager->persist($meet);
            $entityManager->flush();

            return $this->redirectToRoute('app_admin_meet_index');
        }

        return $this->render('admin/meet/new.html.twig', [
            'form' => $form,
        ]);
    }
}
