<?php

namespace App\Controller\Administrateur;

use App\Entity\{Chantier, ChantierPhoto, Dechet, Entite, Utilisateur};
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
  ) {}

  #[Route('', name: 'app_administrateur_chantier_index', methods: ['GET'])]
  public function index(Entite $entite): Response
  {
    return $this->render('administrateur/chantier/index.html.twig', [
      'entite' => $entite,
    ]);
  }

  #[Route('/ajax', name: 'app_administrateur_chantier_ajax', methods: ['POST'])]
  public function ajax(Entite $entite, Request $request, ChantierRepository $repo): JsonResponse
  {
    $draw   = $request->request->getInt('draw', 0);
    $start  = max(0, $request->request->getInt('start', 0));
    $length = $request->request->getInt('length', 25);

    // 🔎 Recherche globale DataTables
    $searchDT = trim((string) (($request->request->all('search')['value'] ?? '')));

    // 🔎 Filtres custom
    $searchCustom  = trim((string) $request->request->get('searchName', ''));
    $statutFilter  = (string) $request->request->get('statutFilter', 'all');
    $semaineFilter = trim((string) $request->request->get('semaineFilter', ''));
    $villeFilter   = trim((string) $request->request->get('villeFilter', ''));

    // 🔀 Fusion recherche DataTables + champ custom
    $search = trim($searchDT . ' ' . $searchCustom);

    // 🔃 ORDER
    $order = (array) $request->request->all('order');
    $col   = (int) ($order[0]['column'] ?? 0);
    $dir   = strtolower((string) ($order[0]['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

    $orderMap = [
      0 => 'c.id',
      1 => 'c.nom',
      2 => 'c.ville',
      3 => 'c.dateDebutPrevisionnelle',
      4 => 'c.statut',
    ];

    // 🔨 QueryBuilder de base
    $qb = $repo->createListQb($entite, $search);

    // =========================
    // ✅ FILTRES DYNAMIQUES
    // =========================

    if ($statutFilter !== 'all') {
      $qb->andWhere('c.statut = :statut')
        ->setParameter('statut', $statutFilter);
    }

    if ($semaineFilter !== '') {
      $qb->andWhere('c.semainePrevisionnelle = :semaine')
        ->setParameter('semaine', $semaineFilter);
    }

    if ($villeFilter !== '') {
      $qb->andWhere('LOWER(c.ville) LIKE :ville')
        ->setParameter('ville', '%' . mb_strtolower($villeFilter) . '%');
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

    $recordsTotal = $repo->countForEntite($entite);

    $qbCount = $repo->createListQb($entite, $search)
      ->select('COUNT(c.id)');

    if ($statutFilter !== 'all') {
      $qbCount->andWhere('c.statut = :statut')
        ->setParameter('statut', $statutFilter);
    }

    if ($semaineFilter !== '') {
      $qbCount->andWhere('c.semainePrevisionnelle = :semaine')
        ->setParameter('semaine', $semaineFilter);
    }

    if ($villeFilter !== '') {
      $qbCount->andWhere('LOWER(c.ville) LIKE :ville')
        ->setParameter('ville', '%' . mb_strtolower($villeFilter) . '%');
    }

    $recordsFiltered = (int) $qbCount->getQuery()->getSingleScalarResult();

    // =========================
    // FORMAT DATA
    // =========================

    $data = [];
    foreach ($rows as $chantier) {
      \assert($chantier instanceof Chantier);

      $data[] = [
        'id' => $chantier->getId(),
        'nom' => $chantier->getNom(),
        'ville' => $chantier->getVille() ?: '—',
        'semaine' => $chantier->getSemainePrevisionnelle() ?: '—',
        'periode' => ($chantier->getDateDebutPrevisionnelle()?->format('d/m/Y') ?? '—')
          . ($chantier->getDateFinPrevisionnelle()
            ? ' → ' . $chantier->getDateFinPrevisionnelle()?->format('d/m/Y')
            : ''),

        // 🔥 VERSION PREMIUM BADGE
        'statut' => sprintf(
          '<span class="badge-soft %s">%s</span>',
          match ($chantier->getStatut()->value ?? $chantier->getStatut()->name ?? '') {
            'BROUILLON' => 'badge-soft-dark',
            'PLANIFIE'  => 'badge-soft-primary',
            'EN_COURS'  => 'badge-soft-warning',
            'TERMINE'   => 'badge-soft-success',
            'ANNULE'    => 'badge-soft-danger',
            default     => 'badge-soft-dark'
          },
          $chantier->getStatut()->label()
        ),

        // 🔥 VERSION PREMIUM RESSOURCES
        'ressources' => sprintf(
          '<div class="d-flex justify-content-center gap-1">
          <span class="badge rounded-pill bg-primary-subtle text-primary border border-primary-subtle">%d H</span>
          <span class="badge rounded-pill bg-warning-subtle text-warning border border-warning-subtle">%d E</span>
          <span class="badge rounded-pill bg-dark-subtle text-dark border border-dark-subtle">%d M</span>
        </div>',
          $chantier->getRessourcesHumaines()->count(),
          $chantier->getRessourcesEngins()->count(),
          $chantier->getRessourcesMateriels()->count()
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

    $isEdit = (bool) $chantier;

    if (!$chantier) {
      $chantier = new Chantier();
      $chantier->setEntite($entite);
      $chantier->setCreateur($user);
    } elseif ($chantier->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createNotFoundException();
    }

    $form = $this->createForm(ChantierType::class, $chantier, [
      'entite' => $entite,
    ]);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
      foreach ($chantier->getDechets() as $dechet) {
        $type = $dechet->getTypeDechet();
        if ($type instanceof Dechet && null === $type->getId()) {
          $type->setEntite($entite);
          $type->setCreateur($user);
          $em->persist($type);
        }
      }

      $uploadPath = $this->getParameter('chantier_photo_upload_dir');

      foreach ($form->get('photos') as $index => $photoForm) {
        /** @var ChantierPhoto|null $photoEntity */
        $photoEntity = $chantier->getPhotos()->get($index);
        if (!$photoEntity) {
          continue;
        }

        $avantFile = $photoForm->get('avantFile')->getData();
        if ($avantFile) {
          $this->photoManager->handleSingleImageUpload(
            file: $avantFile,
            setter: fn(string $name) => $photoEntity->setPhotoAvant($name),
            fileUploader: $this->fileUploader,
            uploadPath: $uploadPath,
            sizeW: 1800,
            sizeH: 1200,
            oldFilename: $photoEntity->getPhotoAvant()
          );
        }

        $apresFile = $photoForm->get('apresFile')->getData();
        if ($apresFile) {
          $this->photoManager->handleSingleImageUpload(
            file: $apresFile,
            setter: fn(string $name) => $photoEntity->setPhotoApres($name),
            fileUploader: $this->fileUploader,
            uploadPath: $uploadPath,
            sizeW: 1800,
            sizeH: 1200,
            oldFilename: $photoEntity->getPhotoApres()
          );
        }

        if (!$photoEntity->getLatitudeAvant() || !$photoEntity->getLongitudeAvant()) {
          if ($photoEntity->getPhotoAvant()) {
            $absoluteAvant = rtrim($uploadPath, '/') . '/' . $photoEntity->getPhotoAvant();
            $gpsAvant = $this->photoGpsExtractor->extractFromFile($absoluteAvant);

            if ($gpsAvant) {
              $photoEntity->setLatitudeAvant($gpsAvant['latitude']);
              $photoEntity->setLongitudeAvant($gpsAvant['longitude']);
              $photoEntity->setSourceLocalisationAvant('exif');
            }
          }
        } elseif (!$photoEntity->getSourceLocalisationAvant()) {
          $photoEntity->setSourceLocalisationAvant('manuel');
        }

        if (!$photoEntity->getLatitudeApres() || !$photoEntity->getLongitudeApres()) {
          if ($photoEntity->getPhotoApres()) {
            $absoluteApres = rtrim($uploadPath, '/') . '/' . $photoEntity->getPhotoApres();
            $gpsApres = $this->photoGpsExtractor->extractFromFile($absoluteApres);

            if ($gpsApres) {
              $photoEntity->setLatitudeApres($gpsApres['latitude']);
              $photoEntity->setLongitudeApres($gpsApres['longitude']);
              $photoEntity->setSourceLocalisationApres('exif');
            }
          }
        } elseif (!$photoEntity->getSourceLocalisationApres()) {
          $photoEntity->setSourceLocalisationApres('manuel');
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

  #[Route('/{id}', name: 'app_administrateur_chantier_show', requirements: ['id' => '\d+'], methods: ['GET'])]
  public function show(Entite $entite, Chantier $chantier): Response
  {
    if ($chantier->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createNotFoundException();
    }

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
    if ($chantier->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createNotFoundException();
    }

    $html = $this->renderView('pdf/chantier.html.twig', [
      'entite' => $entite,
      'chantier' => $chantier,
    ]);

    return $this->pdfManager->streamPdfFromHtml(
      $html,
      sprintf('chantier-%d.pdf', $chantier->getId()),
      'portrait'
    );
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
}
