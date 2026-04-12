<?php

namespace App\Controller\Administrateur;

use App\Entity\{Engin, Entite, Utilisateur};
use Imagine\Gd\Imagine;

use App\Service\FileUploader;
use App\Service\Photo\PhotoManager;
use App\Form\Administrateur\EnginType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response, JsonResponse, RedirectResponse};
use Symfony\Component\Routing\Attribute\Route;
use App\Security\Permission\TenantPermission;
use Symfony\Component\Security\Http\Attribute\IsGranted;


#[Route('/administrateur/{entite}/engin')]
#[IsGranted(TenantPermission::ENGIN_MANAGE, subject: 'entite')]
final class EnginController extends AbstractController
{

    public function __construct(
        private PhotoManager $photoManager,
        private FileUploader $fileUploader,
    ) {}


    #[Route('', name: 'app_administrateur_engin_index', methods: ['GET'])]
    public function index(Entite $entite): Response
    {

        /** @var Utilisateur $user */
        $user = $this->getUser();



        return $this->render(
            'administrateur/engin/index.html.twig',
            [
                'entite' => $entite,

            ]
        );
    }

    #[Route('/ajax', name: 'app_administrateur_engin_ajax', methods: ['POST'])]
    public function ajax(Entite $entite, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $draw   = $request->request->getInt('draw', 0);
        $start  = max(0, $request->request->getInt('start', 0));
        $length = $request->request->getInt('length', 10);

        if ($length <= 0 || $length > 500) {
            $length = 10;
        }

        $search  = (array) $request->request->all('search');
        $searchV = trim((string)($search['value'] ?? ''));

        // Tri DataTables
        $order = (array) $request->request->all('order');
        $orderColIdx = (int) ($order[0]['column'] ?? 0);
        $orderDir = strtolower((string)($order[0]['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

        // Mapping des colonnes (⚠️ uniquement colonnes "réelles" côté Engin)
        // # 0 id, 1 nom, 2 type, 3 immatriculation, 4 alx, 5 total, 6 edenred, 7 actions
        $orderMap = [
            0 => 'b.id',
            1 => 'b.nom',
            2 => 'b.type',
            3 => 'b.immatriculation',
            // ext_* non triables (car calculés)
        ];
        $orderBy = $orderMap[$orderColIdx] ?? 'b.id';

        // Query principale (data)
        $qb = $em->createQueryBuilder()
            ->select('b', 'x') // x = external ids (active)
            ->from(Engin::class, 'b')
            ->leftJoin('b.externalIds', 'x', 'WITH', 'x.active = true')
            ->andWhere('b.entite = :entite')
            ->setParameter('entite', $entite);

        // Search
        if ($searchV !== '') {
            $qb->andWhere('(b.nom LIKE :q OR b.immatriculation LIKE :q)')
                ->setParameter('q', '%' . $searchV . '%');
        }

        // recordsTotal (sans search)
        $recordsTotal = (int) $em->createQueryBuilder()
            ->select('COUNT(DISTINCT b_t.id)')
            ->from(Engin::class, 'b_t')
            ->andWhere('b_t.entite = :entite')
            ->setParameter('entite', $entite)
            ->getQuery()->getSingleScalarResult();

        // recordsFiltered
        $qbCount = clone $qb;
        $qbCount->resetDQLPart('select');
        $qbCount->resetDQLPart('orderBy');
        $recordsFiltered = (int) $qbCount
            ->select('COUNT(DISTINCT b.id)')
            ->getQuery()->getSingleScalarResult();

        /** @var Engin[] $rows */
        $rows = $qb
            ->orderBy($orderBy, $orderDir)
            ->addOrderBy('b.id', 'DESC')
            ->setFirstResult($start)
            ->setMaxResults($length)
            ->getQuery()
            ->getResult();

        $data = [];
        foreach ($rows as $b) {
            if (!$b instanceof Engin) continue;

            $ext = [
                \App\Enum\ExternalProvider::ALX->value => [],
                \App\Enum\ExternalProvider::TOTAL->value => [],
                \App\Enum\ExternalProvider::EDENRED->value => [],
            ];

            /** @var \App\Entity\EnginExternalId $x */
            foreach ($b->getExternalIds() as $x) {
                if (!$x->isActive()) continue;

                $prov = $x->getProvider()->value;
                $val  = $x->getValue();

                // ✅ push sans doublon
                if (!\in_array($val, $ext[$prov], true)) {
                    $ext[$prov][] = $val;
                }
            }

            $data[] = [
                'id'             => $b->getId(),
                'nom'            => $b->getNom() ?: '—',
                'type'           => $b->getType()?->value ?? '—',
                'immatriculation' => $b->getImmatriculation() ?: null,

                'ext_alx'     => $ext[\App\Enum\ExternalProvider::ALX->value] ?? [],
                'ext_total'   => $ext[\App\Enum\ExternalProvider::TOTAL->value] ?? [],
                'ext_edenred' => $ext[\App\Enum\ExternalProvider::EDENRED->value] ?? [],

                'actions'        => $this->renderView('administrateur/engin/_actions.html.twig', [
                    'engin'  => $b,
                    'entite' => $entite,
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






    #[Route('/ajouter', name: 'app_administrateur_engin_ajouter', methods: ['GET', 'POST'])]
    #[Route('/modifier/{id}', name: 'app_administrateur_engin_modifier', methods: ['GET', 'POST'])]
    public function addEdit(Entite $entite, Request $request, EntityManagerInterface $em, ?Engin $engin = null): Response
    {


        /** @var Utilisateur $user */
        $user = $this->getUser();

        $isEdit = (bool) $engin;
        if (!$engin) {
            $engin = new Engin();
            $engin->setCreateur($user);
            $engin->setEntite($entite);
        }

        $form = $this->createForm(EnginType::class, $engin);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {





            // Dossier d’upload (configuré en paramètre)
            $uploadPath = $this->getParameter('engin_upload_dir');




            // 1) Photo de couverture (vignette pour la liste) — redimensionnée 360x240
            $this->photoManager->handleImageUpload(
                form: $form,
                fieldName: 'photoCouverture',
                setter: fn(string $name) => $engin->setPhotoCouverture($name), // ✅ setter
                fileUploader: $this->fileUploader,
                uploadPath: $uploadPath,
                sizeW: 1600,
                sizeH: 600,
                oldFilename: $engin->getPhotoCouverture() // ⚠️ à lire AVANT set
            );






            $em->persist($engin);
            $em->flush();

            $this->addFlash('success', $isEdit ? 'Engin modifié.' : 'Engin ajouté.');
            return $this->redirectToRoute('app_administrateur_engin_index', [
                'entite' => $entite->getId(),
            ]);
        }

        return $this->render('administrateur/engin/form.html.twig', [
            'form'        => $form->createView(),
            'modeEdition' => $isEdit,
            'engin'      => $engin,
            'entite' => $entite,
        ]);
    }

    #[Route('/supprimer/{id}', name: 'app_administrateur_engin_supprimer', methods: ['POST'])]
    public function delete(Entite $entite, EntityManagerInterface $em, Engin $engin, Request $request): RedirectResponse
    {


        // 🔒 cloisonnement entité
        if ($engin->getEntite()?->getId() !== $entite->getId()) {
            throw $this->createNotFoundException();
        }

        // ✅ CSRF (doit matcher le twig)
        $token = (string)$request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('delete_engin_' . $engin->getId(), $token)) {
            $this->addFlash('danger', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('app_administrateur_engin_index', ['entite' => $entite->getId()]);
        }

        $id = $engin->getId();
        $em->remove($engin);
        $em->flush();

        $this->addFlash('success', 'Engin #' . $id . ' supprimé.');
        return $this->redirectToRoute('app_administrateur_engin_index', ['entite' => $entite->getId()]);
    }

    #[Route('/{id}', name: 'app_administrateur_engin_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Entite $entite, Engin $engin): Response
    {
        // 🔒 cloisonnement entité
        if ($engin->getEntite()?->getId() !== $entite->getId()) {
            throw $this->createNotFoundException();
        }

        return $this->render('administrateur/engin/show.html.twig', [
            'entite' => $entite,
            'engin'  => $engin,
        ]);
    }
}
