<?php

namespace App\Controller\Employe;

use App\Entity\Entite;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class EmployeAccessController extends AbstractController
{
  #[Route('/employe/{entite}/acces-refuse', name: 'app_employe_access_denied', methods: ['GET'])]
  public function index(Entite $entite): Response
  {
    return $this->render('security/employe_access_denied.html.twig', [
      'entite' => $entite,
    ]);
  }
}
