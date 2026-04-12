<?php

declare(strict_types=1);

namespace App\Controller\Administrateur;

use App\Entity\Entite;
use App\Entity\Utilisateur;
use App\Form\Administrateur\UtilisateurImportType;
use App\Security\Permission\TenantPermission;
use App\Service\Import\UtilisateurExcelImporter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;


#[Route('/administrateur/{entite}/utilisateur', name: 'app_administrateur_utilisateur_')]
#[IsGranted(TenantPermission::UTILISATEUR_MANAGE, subject: 'entite')]
final class UtilisateurImportController extends AbstractController
{
  public function __construct(private readonly UtilisateurExcelImporter $importer) {}

  #[Route('/import', name: 'import', methods: ['GET', 'POST'])]
  public function import(Entite $entite, Request $request): Response
  {
    $form = $this->createForm(UtilisateurImportType::class);
    $form->handleRequest($request);

    $result = null;

    if ($form->isSubmitted() && $form->isValid()) {
      /** @var \Symfony\Component\HttpFoundation\File\UploadedFile|null $file */
      $file = $form->get('file')->getData();

      if (!$file) {
        $this->addFlash('danger', 'Aucun fichier reçu.');
        return $this->redirectToRoute('app_administrateur_utilisateur_import', ['entite' => $entite->getId()]);
      }

      $me = $this->getUser();
      if (!$me instanceof Utilisateur) {
        throw $this->createAccessDeniedException('Utilisateur non valide.');
      }

      $result = $this->importer->import($entite, $me, $file);

      if (!empty($result['errors'])) {
        $this->addFlash('warning', sprintf(
          "Import terminé avec erreurs — %d importés, %d maj, %d ignorés, %d erreurs. (entêtes ligne %s)",
          $result['imported'],
          $result['updated'],
          $result['skipped'],
          count($result['errors']),
          $result['headerRow'] ?? '?'
        ));
      } else {
        $this->addFlash('success', sprintf(
          "Import réussi — %d importés, %d maj, %d ignorés. (entêtes ligne %s)",
          $result['imported'],
          $result['updated'],
          $result['skipped'],
          $result['headerRow'] ?? '?'
        ));
      }
    }

    return $this->render('administrateur/utilisateur/import.html.twig', [
      'entite' => $entite,
      'form' => $form,
      'result' => $result,
    ]);
  }


  // ...

  #[Route('/import/exemple', name: 'import_example', methods: ['GET'])]
  public function downloadExample(Entite $entite): BinaryFileResponse
  {
    $path = $this->getParameter('kernel.project_dir') . '/public/exemples/exemple-import-employes.xlsx';

    if (!is_file($path)) {
      throw $this->createNotFoundException("Fichier exemple introuvable : $path");
    }

    $response = new BinaryFileResponse($path);
    $response->setContentDisposition(
      ResponseHeaderBag::DISPOSITION_ATTACHMENT,
      'exemple-import-employes.xlsx'
    );

    return $response;
  }
}
