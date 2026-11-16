<?php

namespace App\Controller\Admin;

use App\Repository\StatisticRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AdminManagerController extends AbstractController
{
    #[Route('/admin/manager', name: 'app_admin_admin_manager')]
    public function index(StatisticRepository $statisticRepository): Response
    {
        $statistic = $statisticRepository->find(1);

        return $this->render('admin/admin_manager/index.html.twig', [
            'controller_name' => 'Admin/AdminManagerController',
            'statistic' => $statistic,
        ]);
    }
}
