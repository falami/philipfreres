<?php

namespace App\Controller\Onboarding;

use App\Entity\{Entite, UtilisateurEntite, Utilisateur};
use App\Form\Onboarding\EntiteOnboardingType;
use App\Service\Tenant\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\AsciiSlugger;

final class OnboardingController extends AbstractController
{
  #[Route('/onboarding', name: 'app_onboarding', methods: ['GET', 'POST'])]
  public function index(
    Request $request,
    EntityManagerInterface $em,
    TenantContext $tenant,
  ): Response {
    $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

    /** @var Utilisateur|null $user */
    $user = $this->getUser();
    if (!$user instanceof Utilisateur) {
      return $this->redirectToRoute('app_public_home');
    }


    $entite = new Entite();
    $form = $this->createForm(EntiteOnboardingType::class, $entite);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {


      /** @var UploadedFile|null $logoFile */
      $logoFile = $form->get('logoFile')->getData();

      if ($logoFile) {
        $slugger = new AsciiSlugger();
        $safe = $slugger->slug(pathinfo($logoFile->getClientOriginalName(), PATHINFO_FILENAME))->lower();
        $ext = $logoFile->guessExtension() ?: $logoFile->getClientOriginalExtension() ?: 'bin';
        $filename = sprintf('logo-%s-%s.%s', $safe, bin2hex(random_bytes(6)), $ext);

        $targetDir = $this->getParameter('logo_entite'); // ton param services.yaml
        $logoFile->move($targetDir, $filename);

        // ✅ on stocke juste le nom de fichier en BDD
        $entite->setLogo($filename);
      }




      $conn = $em->getConnection();
      $conn->beginTransaction();

      try {
        // 1) Entite
        $entite->setCreateur($user);
        $entite->setDateCreation(new \DateTimeImmutable());
        $entite->setPublic(false);
        $entite->setIsActive(true);

        // slug provisoire NON NULL
        $entite->setSlug('pending-' . bin2hex(random_bytes(6)));

        // couleurs par défaut
        $entite->setCouleurPrincipal($entite->getCouleurPrincipal() ?: '#233342');
        $entite->setCouleurSecondaire($entite->getCouleurSecondaire() ?: '#0d6efd');
        $entite->setCouleurTertiaire($entite->getCouleurTertiaire() ?: '#F0F0F0');
        $entite->setCouleurQuaternaire($entite->getCouleurQuaternaire() ?: '#0f2336');

        $em->persist($entite);

        // 2) Membership admin
        $ue = new UtilisateurEntite();
        $ue->setUtilisateur($user);
        $ue->setEntite($entite);
        $ue->setRoles([UtilisateurEntite::TENANT_ADMIN]);
        $ue->setCreateur($user);
        $ue->ensureCouleur();
        $em->persist($ue);

        // flush 1 : obtenir ID entite
        $em->flush();

        $entiteId = $entite->getId();
        if (null === $entiteId) {
          throw new \RuntimeException('Impossible de créer l’entité (ID non généré).');
        }

        // slug définitif
        $entite->setSlug('E' . $entiteId);

        // entité courante
        $user->setEntite($entite);

        // flush 2
        $em->flush();


        // 4) Tenant courant
        $tenant->setCurrentEntite($user, $entite);
        $em->flush();

        $conn->commit();
      } catch (\Throwable $e) {
        $conn->rollBack();
        throw $e;
      }

      return $this->redirectToRoute('app_administrateur_billing', [
        'entite' => $entite->getId(),
      ]);
    }

    return $this->render('onboarding/index.html.twig', [
      'form' => $form,
    ]);
  }
}
