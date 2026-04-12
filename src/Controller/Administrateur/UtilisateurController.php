<?php

declare(strict_types=1);

namespace App\Controller\Administrateur;

use App\Entity\{Entite, Utilisateur, UtilisateurEntite};
use Doctrine\ORM\QueryBuilder;
use App\Service\Email\MailerManager;
use Symfony\Component\String\ByteString;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Attribute\Route;
use App\Form\Administrateur\UtilisateurType;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\HttpFoundation\{JsonResponse, RedirectResponse, Request, Response};
use App\Security\Permission\TenantPermission;
use App\Entity\UtilisateurExternalId;
use App\Enum\ExternalProvider;


#[Route('/administrateur/{entite}/utilisateur', name: 'app_administrateur_utilisateur_')]
#[IsGranted(TenantPermission::UTILISATEUR_MANAGE, subject: 'entite')]
final class UtilisateurController extends AbstractController
{
  public function __construct(
    private readonly MailerManager $mailerManager,
    private readonly EntityManagerInterface $em,
    private readonly UserPasswordHasherInterface $passwordHasher,

  ) {}

  /* ===================== LIST ===================== */

  #[Route('', name: 'index', methods: ['GET'])]
  public function index(Entite $entite): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    return $this->render('administrateur/utilisateur/index.html.twig', [
      'entite' => $entite,


    ]);
  }

  #[Route('/ajax', name: 'ajax', methods: ['POST'])]
  public function ajax(Entite $entite, Request $request): JsonResponse
  {
    $draw   = $request->request->getInt('draw', 0);
    $start  = max(0, $request->request->getInt('start', 0));
    $length = $request->request->getInt('length', 10);

    // DataTables peut envoyer -1 (= tout). On borne pour éviter les abus.
    if ($length <= 0 || $length > 500) {
      $length = 10;
    }

    // DataTables search (global)
    $search  = (array) $request->request->all('search');
    $searchV = trim((string) ($search['value'] ?? ''));

    // Filtres custom
    $rolesFilter    = (string) $request->request->get('rolesFilter', 'all');     // ex: TENANT_FORMATEUR
    $verifiedFilter = (string) $request->request->get('verifiedFilter', 'all');  // '1' | '0' | 'all'
    $lockedFilter   = (string) $request->request->get('lockedFilter', 'all');    // '1' | '0' | 'all'
    $searchName     = trim((string) $request->request->get('searchName', ''));

    // Tri DataTables
    $order      = (array) $request->request->all('order');
    $orderDir   = strtolower((string) ($order[0]['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
    $orderColIdx = (int) ($order[0]['column'] ?? 0);

    // Mapping des colonnes DataTables -> champs Doctrine (uniquement des champs sûrs)
    $orderMap = [
      0 => 'u.id',
      1 => 'u.nom',
      2 => 'u.prenom',
      3 => 'u.email',
    ];
    $orderBy = $orderMap[$orderColIdx] ?? 'u.id';

    $applyFilters = function (QueryBuilder $qb, string $uAlias, string $ueAlias) use (
      $rolesFilter,
      $verifiedFilter,
      $lockedFilter,
      $searchName,
      $searchV
    ): void {
      if ($searchV !== '') {
        $qb->andWhere("($uAlias.nom LIKE :dt_q OR $uAlias.prenom LIKE :dt_q OR $uAlias.email LIKE :dt_q)")
          ->setParameter('dt_q', '%' . $searchV . '%');
      }

      if ($searchName !== '') {
        $qb->andWhere("($uAlias.nom LIKE :fb_q OR $uAlias.prenom LIKE :fb_q OR $uAlias.email LIKE :fb_q)")
          ->setParameter('fb_q', '%' . $searchName . '%');
      }

      if ($verifiedFilter === '1' || $verifiedFilter === '0') {
        $qb->andWhere("$uAlias.isVerified = :fb_verified")
          ->setParameter('fb_verified', $verifiedFilter === '1');
      }

      // Locked: verified OU inscriptions > 0
      if ($lockedFilter === '1') {
        $qb->andWhere("($uAlias.isVerified = true OR SIZE($uAlias.inscriptions) > 0)");
      } elseif ($lockedFilter === '0') {
        $qb->andWhere("($uAlias.isVerified = false AND SIZE($uAlias.inscriptions) = 0)");
      }

      if ($rolesFilter !== '' && $rolesFilter !== 'all') {
        // rolesFilter doit valoir ex: "TENANT_FORMATEUR"
        $qb->andWhere("JSON_CONTAINS($ueAlias.roles, :roleJson) = 1")
          ->setParameter('roleJson', json_encode($rolesFilter));
      }
    };

    // 1) Query principale (data)
    $qb = $this->em->createQueryBuilder()
      ->select('u', 'ue')
      ->from(Utilisateur::class, 'u')
      ->innerJoin('u.utilisateurEntites', 'ue', 'WITH', 'ue.entite = :entite')
      ->setParameter('entite', $entite);

    $applyFilters($qb, 'u', 'ue');

    // 2) recordsTotal (sans filtres)
    $qbTotal = $this->em->createQueryBuilder()
      ->select('COUNT(DISTINCT u_t.id)')
      ->from(Utilisateur::class, 'u_t')
      ->innerJoin('u_t.utilisateurEntites', 'ue_t', 'WITH', 'ue_t.entite = :entite')
      ->setParameter('entite', $entite);

    $recordsTotal = (int) $qbTotal->getQuery()->getSingleScalarResult();

    // 3) recordsFiltered (avec filtres)
    $qbFiltered = $this->em->createQueryBuilder()
      ->select('COUNT(DISTINCT u_f.id)')
      ->from(Utilisateur::class, 'u_f')
      ->innerJoin('u_f.utilisateurEntites', 'ue_f', 'WITH', 'ue_f.entite = :entite')
      ->setParameter('entite', $entite);

    $applyFilters($qbFiltered, 'u_f', 'ue_f');
    $recordsFiltered = (int) $qbFiltered->getQuery()->getSingleScalarResult();


    // 4) Pagination + tri
    $rows = $qb
      ->orderBy($orderBy, $orderDir)
      ->addOrderBy('u.id', 'DESC')
      ->setFirstResult($start)
      ->setMaxResults($length)
      ->getQuery()
      ->getResult();

    // ✅ Collecte des utilisateurs paginés
    $users = [];
    $userIds = [];

    foreach ($rows as $row) {
      $u = \is_array($row) ? ($row[0] ?? $row['u'] ?? null) : $row;
      if (!$u instanceof Utilisateur) continue;

      $users[] = $u;
      if ($u->getId() !== null) $userIds[] = $u->getId();
    }

    // ✅ Récupère TOUS les externalIds actifs en 1 requête
    $extMap = []; // [userId][providerValue] => list<string>
    if ($userIds !== []) {
      $extRows = $this->em->createQueryBuilder()
        ->select('x', 'u')
        ->from(UtilisateurExternalId::class, 'x')
        ->innerJoin('x.utilisateur', 'u')
        ->andWhere('u.id IN (:ids)')
        ->andWhere('x.active = true')
        ->setParameter('ids', $userIds)
        ->getQuery()
        ->getResult();

      foreach ($extRows as $x) {
        if (!$x instanceof UtilisateurExternalId) continue;

        $uid = $x->getUtilisateur()?->getId();
        if (!$uid) continue;

        $prov = $x->getProvider()->value; // 'ALX' / 'TOTAL' / 'EDENRED'
        $val  = $x->getValue();

        $extMap[$uid][$prov] ??= [];
        // évite les doublons au cas où
        if (!\in_array($val, $extMap[$uid][$prov], true)) {
          $extMap[$uid][$prov][] = $val;
        }
      }
    }

    // 5) Formatage
    $data = [];

    foreach ($users as $u) {
      // ✅ récupère l’UE pour cette entite
      $ue = $u->getUtilisateurEntites()->filter(
        fn(UtilisateurEntite $ue) => $ue->getEntite() === $entite
      )->first() ?: null;

      $uid = $u->getId() ?? 0;

      // ✅ Chaque provider => LISTE de valeurs (et pas une seule)
      $extAlx     = $extMap[$uid][ExternalProvider::ALX->value]     ?? [];
      $extTotal   = $extMap[$uid][ExternalProvider::TOTAL->value]   ?? [];
      $extEdenred = $extMap[$uid][ExternalProvider::EDENRED->value] ?? [];

      $data[] = [
        'id'          => $u->getId(),
        'nom'         => $u->getNom() ?: '—',
        'prenom'      => $u->getPrenom() ?: '—',
        'email'       => $u->getEmail() ?: '—',

        // ✅ maintenant ce sont des arrays
        'ext_alx'     => $extAlx,
        'ext_total'   => $extTotal,
        'ext_edenred' => $extEdenred,

        'roles'       => $ue?->getRoles() ?? [UtilisateurEntite::TENANT_EMPLOYE],
        'verified'    => $u->isVerified() ? 'Oui' : 'Non',
        'actions'     => $this->renderView('administrateur/utilisateur/_actions.html.twig', [
          'entite'      => $entite,
          'utilisateur' => $u,
          'ue'          => $ue,
        ]),
      ];
    }

    return new JsonResponse([
      'draw'            => $draw,
      'recordsTotal'    => $recordsTotal,
      'recordsFiltered' => $recordsFiltered,
      'data'            => $data,
    ]);
  }

  /* ===================== ADD / EDIT ===================== */

  #[Route('/ajouter', name: 'ajouter', methods: ['GET', 'POST'])]
  #[Route('/modifier/{id}', name: 'modifier', methods: ['GET', 'POST'])]
  public function addEdit(Entite $entite, Request $request, ?Utilisateur $utilisateur = null): Response
  {


    /** @var Utilisateur $user */
    $user = $this->getUser();

    $isEdit = (bool) $utilisateur;

    if (!$utilisateur) {
      $utilisateur = new Utilisateur();
      $utilisateur->setEntite($entite);
      $utilisateur->setIsVerified(false);
      $utilisateur->setCreateur($user);
      $utilisateur->setRoles(["ROLE_USER"]);
    } else {
      $this->assertUtilisateurInEntite($entite, $utilisateur);
    }

    $ue = $this->em->getRepository(UtilisateurEntite::class)->findOneBy([
      'entite' => $entite,
      'utilisateur' => $utilisateur,
    ]);

    $canSetHighRoles =
      $this->isGranted('ROLE_SUPER_ADMIN')
      || $this->isGranted(TenantPermission::ADMIN, $entite);

    $form = $this->createForm(UtilisateurType::class, $utilisateur, [
      'entite' => $entite,
      'ueRoles' => $ue?->getRoles() ?? [UtilisateurEntite::TENANT_EMPLOYE], // comme tu fais déjà
      'can_set_high_roles' => $canSetHighRoles,
    ]);

    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {


      // 2) Lien Utilisateur <-> Entite via UtilisateurEntite
      // ✅ 2) Lien Utilisateur <-> Entite via UtilisateurEntite
      $ue = $this->em->getRepository(UtilisateurEntite::class)->findOneBy([
        'entite' => $entite,
        'utilisateur' => $utilisateur,
      ]);

      if (!$ue) {
        $ue = new UtilisateurEntite();
        $ue->setCreateur($user);
        $ue->setUtilisateur($utilisateur);
        $ue->setEntite($entite);
        $this->em->persist($ue);
      }

      $rolesFromForm = (array) ($form->has('ueRoles') ? $form->get('ueRoles')->getData() : []);

      if (!$canSetHighRoles) {
        $rolesFromForm = array_values(array_filter(
          $rolesFromForm,
          fn($r) => !$this->isHighRole((string) $r) // ✅ uniquement les rôles élevés
        ));
      }

      // ✅ sécurité : on garantit au moins EMPLOYE
      if ($rolesFromForm === []) {
        $rolesFromForm = [UtilisateurEntite::TENANT_EMPLOYE];
      }

      $ue->setRoles($rolesFromForm);

      $ue->setRoles($rolesFromForm ?: [UtilisateurEntite::TENANT_EMPLOYE]);

      $ue->ensureCouleur();

      // 3) Photo (mapped=false)
      if ($form->has('photo')) {
        /** @var UploadedFile|null $photoFile */
        $photoFile = $form->get('photo')->getData();

        if ($photoFile instanceof UploadedFile) {
          $newName = bin2hex(random_bytes(8)) . '.' . ($photoFile->guessExtension() ?: 'jpg');

          $photoFile->move(
            $this->getParameter('kernel.project_dir') . '/public/uploads/photos/utilisateur',
            $newName
          );

          $utilisateur->setPhoto($newName);
        }
      }





      // 5) Nouveau user : password + reset token (UNE SEULE FOIS)
      if (!$isEdit) {
        $plainPassword = ByteString::fromRandom(20)->toString();

        $hashedPassword = $this->passwordHasher->hashPassword($utilisateur, $plainPassword);
        $utilisateur->setPassword($hashedPassword);

        $this->initResetToken($utilisateur);
      }



      $this->em->persist($utilisateur);
      $this->em->flush();

      $this->addFlash('success', $isEdit ? 'Utilisateur modifié.' : 'Utilisateur créé (reset mot de passe à envoyer).');

      return $this->redirectToRoute('app_administrateur_utilisateur_index', [
        'entite' => $entite->getId(),
      ]);
    }



    $ue = $this->em->getRepository(UtilisateurEntite::class)
      ->findOneBy(['entite' => $entite, 'utilisateur' => $utilisateur]);

    return $this->render('administrateur/utilisateur/form.html.twig', [
      'entite'            => $entite,
      'utilisateur'       => $utilisateur,
      'modeEdition'       => $isEdit,
      'form'              => $form->createView(),

      'ueRoles'           => $ue?->getRoles() ?? [UtilisateurEntite::TENANT_EMPLOYE],
      'ueCouleur'         => $ue?->getCouleur(),
    ]);
  }





  /* ===================== RESET PASSWORD ===================== */

  #[Route('/reset-password/{id}', name: 'reset_password', methods: ['POST'])]
  public function resetPassword(Entite $entite, Utilisateur $utilisateur, Request $request): RedirectResponse
  {
    $this->assertUtilisateurInEntite($entite, $utilisateur);

    if (!$this->isCsrfTokenValid('reset_password_' . $utilisateur->getId(), (string) $request->request->get('_token'))) {
      $this->addFlash('danger', 'Token CSRF invalide.');
      return $this->redirectToRoute('app_administrateur_utilisateur_index', ['entite' => $entite->getId()]);
    }

    $this->initResetToken($utilisateur);
    $this->em->flush();

    // adapte si besoin: nom exact de ta méthode
    $this->mailerManager->sendResetPassword($utilisateur, $entite);

    $this->addFlash('success', 'Email de réinitialisation envoyé.');
    return $this->redirectToRoute('app_administrateur_utilisateur_index', ['entite' => $entite->getId()]);
  }



  /* ===================== SEND ACCOUNT VALIDATION EMAIL ===================== */

  #[Route('/send-activation/{id}', name: 'send_activation', methods: ['POST'])]
  public function sendActivationEmail(Entite $entite, Utilisateur $utilisateur, Request $request): RedirectResponse
  {
    $this->assertUtilisateurInEntite($entite, $utilisateur);

    if (!$this->isCsrfTokenValid('send_activation_' . $utilisateur->getId(), (string) $request->request->get('_token'))) {
      $this->addFlash('danger', 'Token CSRF invalide.');
      return $this->redirectToRoute('app_administrateur_utilisateur_index', ['entite' => $entite->getId()]);
    }

    // ✅ On (re)génère un token + expiration plus longue (activation)
    $this->initActivationToken($utilisateur);
    $this->em->flush();

    $this->mailerManager->sendAccountCreatedValidation($utilisateur, $entite);

    $this->addFlash('success', 'Email d’activation envoyé.');
    return $this->redirectToRoute('app_administrateur_utilisateur_index', ['entite' => $entite->getId()]);
  }

  /** Token d’activation : plus long que reset */
  private function initActivationToken(Utilisateur $u): void
  {
    $u->setResetToken(ByteString::fromRandom(32)->toString());
    $u->setResetTokenExpiresAt(new \DateTimeImmutable('+48 hours')); // ✅ tu ajustes
  }


  /* ===================== DELETE USER ===================== */

  // ⚠️ J’ai corrigé en POST + CSRF (le GET delete, c’est dangereux)
  #[Route('/supprimer/{id}', name: 'supprimer', methods: ['POST'])]
  public function delete(Entite $entite, Utilisateur $utilisateur, Request $request): RedirectResponse
  {
    $this->assertUtilisateurInEntite($entite, $utilisateur);

    if (!$this->isCsrfTokenValid('delete_user_' . $utilisateur->getId(), (string) $request->request->get('_token'))) {
      $this->addFlash('danger', 'Token CSRF invalide.');
      return $this->redirectToRoute('app_administrateur_utilisateur_index', ['entite' => $entite->getId()]);
    }


    $id = $utilisateur->getId();
    $this->em->remove($utilisateur);
    $this->em->flush();

    $this->addFlash('success', 'Utilisateur #' . $id . ' supprimé.');
    return $this->redirectToRoute('app_administrateur_utilisateur_index', ['entite' => $entite->getId()]);
  }


  /* ===================== HELPERS ===================== */

  private function assertUtilisateurInEntite(Entite $entite, Utilisateur $utilisateur): void
  {
    $ue = $this->em->getRepository(UtilisateurEntite::class)->findOneBy([
      'entite' => $entite,
      'utilisateur' => $utilisateur,
    ]);

    if (!$ue) {
      throw $this->createNotFoundException('Utilisateur introuvable pour cette entité.');
    }
  }


  private function initResetToken(Utilisateur $u): void
  {
    $u->setResetToken(ByteString::fromRandom(32)->toString());
    $u->setResetTokenExpiresAt(new \DateTimeImmutable('+2 hours'));
  }

  private function isHighRole(string $r): bool
  {
    return \in_array($r, [
      UtilisateurEntite::TENANT_ADMIN,
      // UtilisateurEntite::TENANT_DIRIGEANT, // si tu l’ajoutes plus tard
    ], true);
  }


  #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
  public function show(Entite $entite, Utilisateur $utilisateur): Response
  {
    $this->assertUtilisateurInEntite($entite, $utilisateur);

    return $this->render('administrateur/utilisateur/show.html.twig', [
      'entite'      => $entite,
      'utilisateur' => $utilisateur,
    ]);
  }
}
