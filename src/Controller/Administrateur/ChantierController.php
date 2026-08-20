<?php

namespace App\Controller\Administrateur;

use App\Entity\{Chantier, Dechet, Entite, Utilisateur, Engin, Materiel, Mandataire};
use App\Form\Administrateur\ChantierType;
use App\Repository\ChantierRepository;
use App\Security\Permission\TenantPermission;
use App\Service\FileUploader;
use App\Service\Pdf\PdfManager;
use App\Service\Photo\PhotoManager;
use Doctrine\ORM\EntityManagerInterface as EM;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, RedirectResponse, Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use App\Enum\{EnginType, MaterielStatut};
use App\Repository\EnginRepository;
use App\Repository\MaterielRepository;
use App\Repository\DechetRepository;
use App\Repository\UtilisateurRepository;
use App\Enum\ChantierStatut;
use App\Repository\MandataireRepository;

#[Route('/administrateur/{entite}/chantier')]
#[IsGranted(TenantPermission::CHANTIER_MANAGE, subject: 'entite')]
final class ChantierController extends AbstractController
{
  public function __construct(
    private readonly PhotoManager $photoManager,
    private readonly FileUploader $fileUploader,
    private readonly PdfManager $pdfManager,
    private readonly HttpClientInterface $httpClient,
    private readonly \App\Service\Photo\PhotoGpsExtractor $photoGpsExtractor,
    #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%env(GEOAPIFY_API_KEY)%')]
    private readonly string $geoapifyApiKey,
  ) {}

  #[Route('', name: 'app_administrateur_chantier_index', methods: ['GET'])]
  public function index(Entite $entite, MandataireRepository $mandataireRepo): Response
  {
    $mandataires = $mandataireRepo->createQueryBuilder('m')
      ->andWhere('m.entite = :entite')
      ->andWhere('m.actif = true')
      ->setParameter('entite', $entite)
      ->orderBy('m.societe', 'ASC')
      ->addOrderBy('m.nom', 'ASC')
      ->getQuery()
      ->getResult();

    return $this->render('administrateur/chantier/index.html.twig', [
      'entite' => $entite,
      'mandataires' => $mandataires,
    ]);
  }

  #[Route('/ajax', name: 'app_administrateur_chantier_ajax', methods: ['GET', 'POST'])]
  public function ajax(Entite $entite, Request $request, ChantierRepository $repo): JsonResponse
  {

    /** @var Utilisateur $user */
    $user = $this->getUser();

    $isTenantAdmin = $this->isTenantAdmin($entite);



    $draw   = $request->query->getInt('draw', 0);
    $start  = max(0, $request->query->getInt('start', 0));
    $length = $request->query->getInt('length', 25);

    // 🔎 Recherche globale DataTables
    $searchDT = trim((string) $request->query->get('searchValue', ''));

    // 🔎 Filtres custom
    $searchCustom  = trim((string) $request->query->get('searchName', ''));
    $statutFilter  = (string) $request->query->get('statutFilter', 'all');
    $semaineFilter = trim((string) $request->query->get('semaineFilter', ''));
    $villeFilter   = trim((string) $request->query->get('villeFilter', ''));
    $mandataireFilter = (string) $request->query->get('mandataireFilter', 'all');

    // 🔀 Fusion recherche DataTables + champ custom
    $search = trim($searchDT . ' ' . $searchCustom);


    $qb = $repo->createVisibleListQb($entite, $user, $isTenantAdmin, $search);

    // 🔃 ORDER
    $col = $request->query->getInt('orderColumn', 0);
    $dir = strtolower((string) $request->query->get('orderDir', 'desc')) === 'asc' ? 'ASC' : 'DESC';

    $orderMap = [
      0 => 'c.id',
      1 => 'c.nom',
      2 => 'c.id', // chef de chantier non trié SQL
      3 => 'c.id', // mandataires non trié SQL
      4 => 'c.ville',
      5 => 'c.dateDebutPrevisionnelle',
      6 => 'c.dateDebutPrevisionnelle',
      7 => 'c.statut',
    ];


    // =========================
    // ✅ FILTRES DYNAMIQUES
    // =========================

    if ($statutFilter !== 'all') {
      $qb->andWhere('c.statut = :statut')
        ->setParameter('statut', $statutFilter);
    }

    if ($semaineFilter !== '') {
      $year = (int) date('Y');
      $week = (int) $semaineFilter;

      $startOfWeek = new \DateTimeImmutable();
      $startOfWeek = $startOfWeek->setISODate($year, $week)->setTime(0, 0, 0);

      $endOfWeek = $startOfWeek->modify('+7 days');

      $qb->andWhere('c.dateDebutPrevisionnelle >= :startWeek')
        ->andWhere('c.dateDebutPrevisionnelle < :endWeek')
        ->setParameter('startWeek', $startOfWeek)
        ->setParameter('endWeek', $endOfWeek);
    }

    if ($villeFilter !== '') {
      $qb->andWhere('LOWER(c.ville) LIKE :ville')
        ->setParameter('ville', '%' . mb_strtolower($villeFilter) . '%');
    }


    if ($mandataireFilter !== 'all' && $mandataireFilter !== '') {
      $qb
        ->innerJoin('c.mandataires', 'mf')
        ->andWhere('mf.id = :mandataireFilter')
        ->setParameter('mandataireFilter', (int) $mandataireFilter);
    }

    // =========================
    // PAGINATION + TRI
    // =========================

    $qb->orderBy($orderMap[$col] ?? 'c.id', $dir)
      ->addOrderBy('c.id', 'DESC')
      ->setFirstResult($start)
      ->setMaxResults($length);

    $rows = $qb->getQuery()->getResult();

    // =========================
    // COUNT
    // =========================

    $recordsTotal = $repo->countVisibleForUser($entite, $user, $isTenantAdmin);

    $qbCount = $repo->createVisibleListQb($entite, $user, $isTenantAdmin, $search)
      ->select('COUNT(DISTINCT c.id)');

    if ($statutFilter !== 'all') {
      $qbCount->andWhere('c.statut = :statut')
        ->setParameter('statut', $statutFilter);
    }

    if ($semaineFilter !== '') {
      $year = (int) date('Y');
      $week = (int) $semaineFilter;

      $startOfWeek = new \DateTimeImmutable();
      $startOfWeek = $startOfWeek->setISODate($year, $week)->setTime(0, 0, 0);

      $endOfWeek = $startOfWeek->modify('+7 days');

      $qbCount->andWhere('c.dateDebutPrevisionnelle >= :startWeek')
        ->andWhere('c.dateDebutPrevisionnelle < :endWeek')
        ->setParameter('startWeek', $startOfWeek)
        ->setParameter('endWeek', $endOfWeek);
    }

    if ($villeFilter !== '') {
      $qbCount->andWhere('LOWER(c.ville) LIKE :ville')
        ->setParameter('ville', '%' . mb_strtolower($villeFilter) . '%');
    }

    if ($mandataireFilter !== 'all' && $mandataireFilter !== '') {
      $qbCount
        ->innerJoin('c.mandataires', 'mf_count')
        ->andWhere('mf_count.id = :mandataireFilter')
        ->setParameter('mandataireFilter', (int) $mandataireFilter);
    }

    $recordsFiltered = (int) $qbCount->getQuery()->getSingleScalarResult();

    // =========================
    // FORMAT DATA
    // =========================

    $data = [];
    foreach ($rows as $chantier) {
      \assert($chantier instanceof Chantier);

      $nbHumains = $chantier->getNbRessourcesHumaines();
      $nbEngins = $chantier->getNbRessourcesEngins();
      $nbMateriels = $chantier->getNbRessourcesMateriels();

      $utilisateursAffectes = $chantier->getUtilisateursAffectes();

      $chefChantier = $utilisateursAffectes->isEmpty()
        ? '<span class="badge-soft badge-soft-dark">Non défini</span>'
        : implode('<br>', array_map(
          fn($u) => '<span class="badge-soft badge-soft-primary">' . trim(($u->getPrenom() ?? '') . ' ' . ($u->getNom() ?? '')) . '</span>',
          $utilisateursAffectes->toArray()
        ));

      $data[] = [
        'id' => $chantier->getId(),
        'nom' => $chantier->getNom(),
        'chefChantier' => $chefChantier,
        'ville' => $chantier->getVille() ?: '-',
        'semaine' => $chantier->getSemainePrevisionnelle() ?: '-',
        'periode' => ($chantier->getDateDebutPrevisionnelle()?->format('d/m/Y H:i') ?? '-')
          . ($chantier->getDateFinPrevisionnelle()
            ? ' → ' . $chantier->getDateFinPrevisionnelle()?->format('d/m/Y H:i')
            : ''),

        // 🔥 VERSION PREMIUM BADGE
        'statut' => sprintf(
          '<span class="badge-soft %s">%s</span>',
          match ($chantier->getStatut()->value) {
            'brouillon' => 'badge-soft-dark',
            'en_cours'  => 'badge-soft-warning',
            'termine'   => 'badge-soft-success',
            'archive'   => 'badge-soft-dark',
            default     => 'badge-soft-dark',
          },
          $chantier->getStatut()->label()
        ),

        'mandataires' => $chantier->getMandataires()->isEmpty()
          ? '<span class="badge-soft badge-soft-dark">Non défini</span>'
          : implode('<br>', array_map(
            fn(Mandataire $m) => '<span class="badge-soft badge-soft-primary">'
              . htmlspecialchars((string) $m, ENT_QUOTES, 'UTF-8')
              . '</span>',
            $chantier->getMandataires()->toArray()
          )),

        // 🔥 VERSION PREMIUM RESSOURCES
        'ressources' => sprintf(
          '<div class="d-flex justify-content-center gap-1">
            <span class="badge rounded-pill bg-primary-subtle text-primary border border-primary-subtle">%d H</span>
            <span class="badge rounded-pill bg-warning-subtle text-warning border border-warning-subtle">%d E</span>
            <span class="badge rounded-pill bg-dark-subtle text-dark border border-dark-subtle">%d M</span>
          </div>',
          $nbHumains,
          $nbEngins,
          $nbMateriels
        ),

        'actions' => $this->renderView('administrateur/chantier/_actions.html.twig', [
          'chantier' => $chantier,
          'entite' => $entite,
        ]),
      ];
    }

    return new JsonResponse([
      'draw' => $draw,
      'recordsTotal' => $recordsTotal,
      'recordsFiltered' => $recordsFiltered,
      'data' => $data,
    ]);
  }

  #[Route('/ajouter', name: 'app_administrateur_chantier_ajouter', methods: ['GET', 'POST'])]
  #[Route('/modifier/{id}', name: 'app_administrateur_chantier_modifier', methods: ['GET', 'POST'])]
  public function addEdit(Entite $entite, Request $request, EM $em, ?Chantier $chantier = null): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    $isEdit = $chantier !== null;

    if (!$chantier) {
      $this->denyUnlessCanAdminChantier($entite);

      $chantier = new Chantier();
      $chantier->setEntite($entite);
      $chantier->setCreateur($user);
    } else {
      $this->denyUnlessCanAccessChantier($entite, $chantier);
    }

    $form = $this->createForm(ChantierType::class, $chantier, [
      'entite' => $entite,
      'can_manage_affectations' => $this->isTenantAdmin($entite),
    ]);

    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
      foreach ($chantier->getZones() as $zone) {
        foreach ($zone->getDechets() as $chantierDechet) {
          $type = $chantierDechet->getTypeDechet();

          if ($type instanceof Dechet && null === $type->getId()) {
            $type->setEntite($entite);
            $type->setCreateur($user);
            $em->persist($type);
          }
        }
      }

      $uploadPath = $this->getParameter('chantier_photo_upload_dir');

      foreach ($form->get('zones') as $zoneForm) {

        /** @var \App\Entity\ChantierZone|null $zone */
        $zone = $zoneForm->getData();

        if (!$zone || !$zoneForm->has('photos')) {
          continue;
        }

        foreach ($zoneForm->get('photos') as $photoForm) {

          /** @var \App\Entity\ChantierPhoto|null $photoEntity */
          $photoEntity = $photoForm->getData();

          if (!$photoEntity) {
            continue;
          }

          // =========================
          // PHOTO AVANT
          // =========================

          $avantFile = $photoForm->get('avantFile')->getData();

          if ($avantFile) {

            // On lit les EXIF AVANT de déplacer/redimensionner le fichier
            $gpsAvant = $this->photoGpsExtractor->extractFromFile(
              $avantFile->getPathname()
            );

            $this->photoManager->handleSingleImageUpload(
              file: $avantFile,
              setter: fn(string $name) => $photoEntity->setPhotoAvant($name),
              fileUploader: $this->fileUploader,
              uploadPath: $uploadPath,
              sizeW: 1800,
              sizeH: 1200,
              oldFilename: $photoEntity->getPhotoAvant()
            );

            // Si GPS EXIF présent, il est prioritaire
            if ($gpsAvant) {
              $photoEntity->setLatitudeAvant($gpsAvant['latitude']);
              $photoEntity->setLongitudeAvant($gpsAvant['longitude']);
              $photoEntity->setSourceLocalisationAvant('exif');
            }
          }

          // =========================
          // PHOTO APRÈS
          // =========================

          $apresFile = $photoForm->get('apresFile')->getData();

          if ($apresFile) {

            $gpsApres = $this->photoGpsExtractor->extractFromFile(
              $apresFile->getPathname()
            );

            $this->photoManager->handleSingleImageUpload(
              file: $apresFile,
              setter: fn(string $name) => $photoEntity->setPhotoApres($name),
              fileUploader: $this->fileUploader,
              uploadPath: $uploadPath,
              sizeW: 1800,
              sizeH: 1200,
              oldFilename: $photoEntity->getPhotoApres()
            );

            if ($gpsApres) {
              $photoEntity->setLatitudeApres($gpsApres['latitude']);
              $photoEntity->setLongitudeApres($gpsApres['longitude']);
              $photoEntity->setSourceLocalisationApres('exif');
            }
          }

          // Sécurité : pas de localisation "après" sans photo après
          if (!$photoEntity->getPhotoApres()) {
            $photoEntity->setAdresseApres(null);
            $photoEntity->setLatitudeApres(null);
            $photoEntity->setLongitudeApres(null);
            $photoEntity->setSourceLocalisationApres(null);
            $photoEntity->setDatePriseVueApres(null);
          }
        }
      }

      $em->persist($chantier);
      $em->flush();

      $this->addFlash('success', $isEdit ? 'Chantier modifié avec succès.' : 'Chantier créé avec succès.');

      return $this->redirectToRoute('app_administrateur_chantier_show', [
        'entite' => $entite->getId(),
        'id' => $chantier->getId(),
      ]);
    }

    return $this->render('administrateur/chantier/form.html.twig', [
      'form' => $form->createView(),
      'chantier' => $chantier,
      'entite' => $entite,
      'modeEdition' => $isEdit,
    ]);
  }


  #[Route('/ajax/ressource/creer', name: 'app_administrateur_chantier_ajax_ressource_creer', methods: ['POST'])]
  public function ajaxCreateRessource(
    Entite $entite,
    Request $request,
    EM $em,
    EnginRepository $enginRepo,
    MaterielRepository $materielRepo,
    DechetRepository $dechetRepo,
    UtilisateurRepository $utilisateurRepo,
  ): JsonResponse {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    if (!$this->isCsrfTokenValid('chantier_inline_resource', (string) $request->request->get('_token'))) {
      return new JsonResponse(['ok' => false, 'message' => 'Jeton CSRF invalide.'], 403);
    }

    $kind = trim((string) $request->request->get('kind', ''));
    $nom = trim((string) $request->request->get('nom', ''));

    if ($nom === '') {
      return new JsonResponse(['ok' => false, 'message' => 'Le nom est obligatoire.'], 400);
    }

    $normalizedNom = mb_strtolower($nom);

    switch ($kind) {
      case 'engin':
        $immat = trim((string) $request->request->get('immatriculation', ''));

        $existing = $enginRepo->createQueryBuilder('e')
          ->andWhere('e.entite = :entite')
          ->andWhere('LOWER(e.nom) = :nom OR (:immat != \'\' AND e.immatriculation = :immat)')
          ->setParameter('entite', $entite)
          ->setParameter('nom', $normalizedNom)
          ->setParameter('immat', $immat)
          ->setMaxResults(1)
          ->getQuery()
          ->getOneOrNullResult();

        if ($existing) {
          return new JsonResponse([
            'ok' => false,
            'message' => 'Cet engin existe déjà pour cette entité.'
          ], 409);
        }

        $ressource = new Engin();
        $ressource->setEntite($entite);
        $ressource->setCreateur($user);
        $ressource->setNom($nom);
        $ressource->setType(EnginType::CHARGEUSE);
        $ressource->setAnnee((int) date('Y'));

        if ($immat !== '') {
          $ressource->setImmatriculation($immat);
        }

        break;

      case 'materiel':
        $type = trim((string) $request->request->get('type', ''));
        $numeroSerie = trim((string) $request->request->get('numeroSerie', ''));

        $existing = $materielRepo->createQueryBuilder('m')
          ->andWhere('m.entite = :entite')
          ->andWhere('LOWER(m.nom) = :nom OR (:numeroSerie != \'\' AND m.numeroSerie = :numeroSerie)')
          ->setParameter('entite', $entite)
          ->setParameter('nom', $normalizedNom)
          ->setParameter('numeroSerie', $numeroSerie)
          ->setMaxResults(1)
          ->getQuery()
          ->getOneOrNullResult();

        if ($existing) {
          return new JsonResponse([
            'ok' => false,
            'message' => 'Ce matériel existe déjà pour cette entité.'
          ], 409);
        }

        $ressource = new Materiel();
        $ressource->setEntite($entite);
        $ressource->setCreateur($user);
        $ressource->setNom($nom);
        $ressource->setStatut(MaterielStatut::DISPONIBLE);

        if ($type !== '') {
          $ressource->setType($type);
        }

        if ($numeroSerie !== '') {
          $ressource->setNumeroSerie($numeroSerie);
        }

        break;

      case 'dechet':
        $unite = trim((string) $request->request->get('unite', 'kg')) ?: 'kg';

        $existing = $dechetRepo->createQueryBuilder('d')
          ->andWhere('d.entite = :entite')
          ->andWhere('LOWER(d.nom) = :nom')
          ->setParameter('entite', $entite)
          ->setParameter('nom', $normalizedNom)
          ->setMaxResults(1)
          ->getQuery()
          ->getOneOrNullResult();

        if ($existing) {
          return new JsonResponse([
            'ok' => false,
            'message' => 'Ce déchet existe déjà pour cette entité.'
          ], 409);
        }

        $ressource = new Dechet();
        $ressource->setEntite($entite);
        $ressource->setCreateur($user);
        $ressource->setNom($nom);
        $ressource->setUnite($unite);

        break;

      case 'utilisateur':
        $prenom = trim((string) $request->request->get('prenom', ''));
        $email = trim((string) $request->request->get('email', ''));

        if ($prenom === '') {
          return new JsonResponse(['ok' => false, 'message' => 'Le prénom est obligatoire.'], 400);
        }

        $qb = $utilisateurRepo->createQueryBuilder('u')
          ->andWhere('LOWER(u.nom) = :nom')
          ->andWhere('LOWER(u.prenom) = :prenom')
          ->setParameter('nom', mb_strtolower($nom))
          ->setParameter('prenom', mb_strtolower($prenom))
          ->setMaxResults(1);

        if ($email !== '') {
          $qb->orWhere('LOWER(u.email) = :email')
            ->setParameter('email', mb_strtolower($email));
        }

        $existing = $qb->getQuery()->getOneOrNullResult();

        if ($existing) {
          return new JsonResponse([
            'ok' => false,
            'message' => 'Cette ressource humaine existe déjà.'
          ], 409);
        }

        if ($email === '') {
          $safe = mb_strtolower(preg_replace('/[^a-zA-Z0-9]+/', '.', $prenom . '.' . $nom));
          $email = trim($safe, '.') . '+' . uniqid() . '@local.invalid';
        }

        $ressource = new Utilisateur();
        $ressource->setNom($nom);
        $ressource->setPrenom($prenom);
        $ressource->setEmail($email);
        $ressource->setRoles(['ROLE_USER']);
        $ressource->setPassword(bin2hex(random_bytes(32)));
        $ressource->setIsVerified(true);
        $ressource->setEntite($entite);
        $ressource->setCreateur($user);
        $ressource->setDateCreation(new \DateTimeImmutable());

        break;

      default:
        return new JsonResponse(['ok' => false, 'message' => 'Type de ressource invalide.'], 400);
    }

    $em->persist($ressource);
    $em->flush();

    return new JsonResponse([
      'ok' => true,
      'kind' => $kind,
      'id' => $ressource->getId(),
      'label' => match ($kind) {
        'utilisateur' => trim($ressource->getPrenom() . ' ' . $ressource->getNom()),
        'materiel' => (string) $ressource,
        default => (string) $ressource->getNom(),
      },
    ]);
  }

  #[Route('/{id}', name: 'app_administrateur_chantier_show', requirements: ['id' => '\d+'], methods: ['GET'])]
  public function show(Entite $entite, Chantier $chantier): Response
  {
    $this->denyUnlessCanAccessChantier($entite, $chantier);

    return $this->render('administrateur/chantier/show.html.twig', [
      'entite' => $entite,
      'chantier' => $chantier,
    ]);
  }

  #[Route('/supprimer/{id}', name: 'app_administrateur_chantier_supprimer', methods: ['POST'])]
  public function delete(Entite $entite, Chantier $chantier, Request $request, EM $em): RedirectResponse
  {
    if ($chantier->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createNotFoundException();
    }

    if (!$this->isCsrfTokenValid('delete_chantier_' . $chantier->getId(), (string) $request->request->get('_token'))) {
      $this->addFlash('danger', 'Jeton CSRF invalide.');
      return $this->redirectToRoute('app_administrateur_chantier_index', ['entite' => $entite->getId()]);
    }

    $em->remove($chantier);
    $em->flush();

    $this->addFlash('success', 'Chantier supprimé.');

    return $this->redirectToRoute('app_administrateur_chantier_index', ['entite' => $entite->getId()]);
  }

  #[Route('/{id}/pdf', name: 'app_administrateur_chantier_pdf', methods: ['GET'])]
  public function pdf(Entite $entite, Chantier $chantier): Response
  {
    $this->denyUnlessCanAccessChantier($entite, $chantier);

    $photoMaps = [];

    foreach ($chantier->getZones() as $zone) {
      foreach ($zone->getPhotos() as $photo) {
        $photoMaps[$photo->getId()] = [
          'avant' => $this->buildStaticMapBase64(
            $photo->getLatitudeAvant(),
            $photo->getLongitudeAvant()
          ),
          'apres' => $this->buildStaticMapBase64(
            $photo->getLatitudeApres(),
            $photo->getLongitudeApres()
          ),
        ];
      }
    }

    $html = $this->renderView('pdf/chantier.html.twig', [
      'entite' => $entite,
      'chantier' => $chantier,
      'photoMaps' => $photoMaps,
    ]);

    return $this->pdfManager->streamPdfFromHtml(
      $html,
      sprintf('compte-rendu-chantier-%d.pdf', $chantier->getId()),
      'portrait'
    );
  }


  private function buildStaticMapBase64(?string $lat, ?string $lng): ?string
  {
    if (!$lat || !$lng) {
      return null;
    }

    $lat = str_replace(',', '.', trim($lat));
    $lng = str_replace(',', '.', trim($lng));

    if (!is_numeric($lat) || !is_numeric($lng)) {
      return null;
    }

    if (trim($this->geoapifyApiKey) === '') {
      return $this->buildFallbackMapSvg($lat, $lng);
    }

    try {
      $query = http_build_query([
        'style' => 'osm-bright',
        'width' => 720,
        'height' => 300,
        'center' => 'lonlat:' . $lng . ',' . $lat,
        'zoom' => 16,
        'marker' => 'lonlat:' . $lng . ',' . $lat . ';color:#e11d48;size:medium',
        'apiKey' => trim($this->geoapifyApiKey),
      ], '', '&', PHP_QUERY_RFC3986);

      $url = 'https://maps.geoapify.com/v1/staticmap?' . $query;

      $response = $this->httpClient->request('GET', $url, [
        'timeout' => 20,
        'headers' => [
          'Accept' => 'image/png,image/*,*/*',
          'User-Agent' => 'PhilipFreres/1.0',
        ],
      ]);

      $statusCode = $response->getStatusCode();
      $content = $response->getContent(false);
      $contentType = $response->getHeaders(false)['content-type'][0] ?? '';

      if (
        $statusCode !== 200 ||
        !$content ||
        strlen($content) < 1000 ||
        !str_contains($contentType, 'image')
      ) {
        return $this->buildFallbackMapSvg($lat, $lng);
      }

      return 'data:image/png;base64,' . base64_encode($content);
    } catch (\Throwable) {
      return $this->buildFallbackMapSvg($lat, $lng);
    }
  }

  private function buildFallbackMapSvg(string $lat, string $lng): string
  {
    $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="360" height="150" viewBox="0 0 360 150">
  <rect width="360" height="150" fill="#f6f8fb"/>
  <path d="M0 35 H360 M0 75 H360 M0 115 H360 M70 0 V150 M145 0 V150 M220 0 V150 M295 0 V150"
        stroke="#d8dee8" stroke-width="1"/>
  <circle cx="180" cy="72" r="12" fill="#e11d48"/>
  <circle cx="180" cy="72" r="5" fill="#ffffff"/>
  <text x="180" y="112" text-anchor="middle" font-family="DejaVu Sans, Arial" font-size="12" fill="#1f2937">
    Position GPS
  </text>
  <text x="180" y="130" text-anchor="middle" font-family="DejaVu Sans, Arial" font-size="10" fill="#6b7280">
    $lat, $lng
  </text>
</svg>
SVG;

    return 'data:image/svg+xml;base64,' . base64_encode($svg);
  }

  #[Route('/geocode', name: 'app_administrateur_chantier_geocode', methods: ['GET'])]
  public function geocode(Entite $entite, Request $request): JsonResponse
  {
    $query = trim((string) $request->query->get('q', ''));

    if ($query === '') {
      return new JsonResponse([
        'ok' => false,
        'message' => 'Adresse vide.'
      ], 400);
    }

    try {
      $response = $this->httpClient->request('GET', 'https://nominatim.openstreetmap.org/search', [
        'query' => [
          'q' => $query,
          'format' => 'jsonv2',
          'limit' => 1,
          'addressdetails' => 1,
        ],
        'headers' => [
          'User-Agent' => 'PhilipFreres/1.0',
          'Accept' => 'application/json',
        ],
      ]);

      $data = $response->toArray(false);

      if (empty($data[0]['lat']) || empty($data[0]['lon'])) {
        return new JsonResponse([
          'ok' => false,
          'message' => 'Adresse introuvable.'
        ], 404);
      }

      return new JsonResponse([
        'ok' => true,
        'latitude' => (string) $data[0]['lat'],
        'longitude' => (string) $data[0]['lon'],
        'display_name' => $data[0]['display_name'] ?? $query,
      ]);
    } catch (\Throwable) {
      return new JsonResponse([
        'ok' => false,
        'message' => 'Erreur lors du géocodage.'
      ], 500);
    }
  }


  #[Route('/reverse-geocode', name: 'app_administrateur_chantier_reverse_geocode', methods: ['GET'])]
  public function reverseGeocode(Entite $entite, Request $request): JsonResponse
  {
    $lat = trim((string) $request->query->get('lat', ''));
    $lng = trim((string) $request->query->get('lng', ''));

    if ($lat === '' || $lng === '') {
      return new JsonResponse([
        'ok' => false,
        'message' => 'Coordonnées manquantes.'
      ], 400);
    }

    try {
      $response = $this->httpClient->request('GET', 'https://nominatim.openstreetmap.org/reverse', [
        'query' => [
          'lat' => $lat,
          'lon' => $lng,
          'format' => 'jsonv2',
          'addressdetails' => 1,
        ],
        'headers' => [
          'User-Agent' => 'PhilipFreres/1.0',
          'Accept' => 'application/json',
        ],
      ]);

      $data = $response->toArray(false);

      return new JsonResponse([
        'ok' => true,
        'display_name' => $data['display_name'] ?? null,
      ]);
    } catch (\Throwable) {
      return new JsonResponse([
        'ok' => false,
        'message' => 'Erreur lors du reverse geocoding.'
      ], 500);
    }
  }


  private function isTenantAdmin(Entite $entite): bool
  {
    return $this->isGranted(TenantPermission::ADMIN, $entite);
  }

  private function canAccessChantier(Entite $entite, Chantier $chantier): bool
  {
    if ($chantier->getEntite()?->getId() !== $entite->getId()) {
      return false;
    }

    if ($this->isTenantAdmin($entite)) {
      return true;
    }

    if ($chantier->getStatut() === ChantierStatut::BROUILLON) {
      return false;
    }

    /** @var Utilisateur $user */
    $user = $this->getUser();

    return $chantier->isAffecteA($user);
  }

  private function denyUnlessCanAccessChantier(Entite $entite, Chantier $chantier): void
  {
    if (!$this->canAccessChantier($entite, $chantier)) {
      throw $this->createAccessDeniedException('Vous n’avez pas accès à ce chantier.');
    }
  }

  private function denyUnlessCanAdminChantier(Entite $entite): void
  {
    if (!$this->isTenantAdmin($entite)) {
      throw $this->createAccessDeniedException('Seul un administrateur peut créer, supprimer ou affecter un chantier.');
    }
  }

  #[Route('/ajax/mandataire/creer', name: 'app_administrateur_chantier_ajax_mandataire_creer', methods: ['POST'])]
  public function ajaxCreateMandataire(
    Entite $entite,
    Request $request,
    EM $em,
    MandataireRepository $mandataireRepo,
  ): JsonResponse {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    if (!$this->isCsrfTokenValid('chantier_inline_mandataire', (string) $request->request->get('_token'))) {
      return new JsonResponse([
        'ok' => false,
        'message' => 'Jeton CSRF invalide.',
      ], 403);
    }

    $nom = trim((string) $request->request->get('nom', ''));
    $societe = trim((string) $request->request->get('societe', ''));
    $email = trim((string) $request->request->get('email', ''));
    $telephone = trim((string) $request->request->get('telephone', ''));
    $adresse = trim((string) $request->request->get('adresse', ''));
    $codePostal = trim((string) $request->request->get('codePostal', ''));
    $ville = trim((string) $request->request->get('ville', ''));
    $commentaire = trim((string) $request->request->get('commentaire', ''));

    if ($nom === '') {
      return new JsonResponse([
        'ok' => false,
        'message' => 'Le nom du mandataire est obligatoire.',
      ], 400);
    }

    $existing = $mandataireRepo->createQueryBuilder('m')
      ->andWhere('m.entite = :entite')
      ->andWhere('LOWER(m.nom) = :nom')
      ->andWhere('LOWER(COALESCE(m.societe, \'\')) = :societe')
      ->setParameter('entite', $entite)
      ->setParameter('nom', mb_strtolower($nom))
      ->setParameter('societe', mb_strtolower($societe))
      ->setMaxResults(1)
      ->getQuery()
      ->getOneOrNullResult();

    if ($existing instanceof Mandataire) {
      return new JsonResponse([
        'ok' => false,
        'message' => 'Ce mandataire existe déjà pour cette entité.',
      ], 409);
    }

    $mandataire = new Mandataire();
    $mandataire
      ->setEntite($entite)
      ->setCreateur($user)
      ->setNom($nom)
      ->setSociete($societe ?: null)
      ->setEmail($email ?: null)
      ->setTelephone($telephone ?: null)
      ->setAdresse($adresse ?: null)
      ->setCodePostal($codePostal ?: null)
      ->setVille($ville ?: null)
      ->setCommentaire($commentaire ?: null)
      ->setActif(true);

    $logoFile = $request->files->get('logoFile');

    if ($logoFile) {
      $uploadDir = $this->getParameter('kernel.project_dir') . '/public/uploads/mandataires/logos';

      if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0775, true);
      }

      $extension = $logoFile->guessExtension() ?: 'jpg';
      $filename = 'mandataire-' . uniqid('', true) . '.' . $extension;

      $logoFile->move($uploadDir, $filename);

      $mandataire->setLogo($filename);
    }

    $em->persist($mandataire);
    $em->flush();

    return new JsonResponse([
      'ok' => true,
      'id' => $mandataire->getId(),
      'label' => (string) $mandataire,
    ]);
  }
}
