<?php
// src/Controller/Public/PublicController.php
declare(strict_types=1);

namespace App\Controller\Public;

use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use App\Form\ContactType;
use Symfony\Component\Mailer\MailerInterface;

#[Route('/')]
final class PublicController extends AbstractController
{
    public function __construct() {}

    #[Route('', name: 'app_public', methods: ['GET'])]
    public function index(): Response
    {
        return $this->redirectToRoute('app_login');
    }



    #[Route('/contact', name: 'app_public_contact', methods: ['GET', 'POST'])]
    public function contact(Request $request, MailerInterface $mailer): Response
    {
        $form = $this->createForm(ContactType::class);
        $form->handleRequest($request);

        // pour revenir sur la page d’où vient le footer
        $referer = $request->headers->get('referer');
        $fallbackRedirect = $this->generateUrl('app_public_contact');

        if ($form->isSubmitted() && !$form->isValid()) {
            // IMPORTANT : si tu rediriges, tu perds les erreurs -> on les met en flash
            $messages = [];
            foreach ($form->getErrors(true) as $error) {
                $messages[] = $error->getMessage();
            }
            $this->addFlash('danger', implode(' • ', array_unique($messages)));

            return $this->redirect($referer ?: $fallbackRedirect);
        }

        if ($form->isSubmitted() && $form->isValid()) {

            // honeypot
            if ($form->get('website')->getData()) {
                $this->addFlash('danger', 'Une erreur est survenue.');
                return $this->redirect($referer ?: $fallbackRedirect);
            }

            // tempo minimale
            $startedAt = (int) $form->get('startedAt')->getData();
            if ($startedAt > 0 && (time() - $startedAt) < 2) {
                $this->addFlash('danger', 'Veuillez réessayer.');
                return $this->redirect($referer ?: $fallbackRedirect);
            }

            $data = $form->getData();

            $email = (new Email())
                ->from(new Address('no-reply@jeroensnow.fr', 'Philip Frères'))
                ->replyTo($data['email'])
                ->to('contact@jeroensnow.fr')
                ->subject('Nouveau message de contact — ' . $data['nom'])
                ->text("Nom : {$data['nom']}\nEmail : {$data['email']}\n\nMessage :\n{$data['message']}");

            try {
                $mailer->send($email);
                $this->addFlash('success', 'Votre message a bien été envoyé !');
            } catch (\Throwable $e) {
                $this->addFlash('danger', "L'envoi a échoué. Réessayez plus tard.");
            }

            return $this->redirect($referer ?: $fallbackRedirect);
        }

        // page dédiée /contact
        return $this->render('public/contact.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/_partials/footer-contact-form', name: 'app_public_footer_contact_form', methods: ['GET'])]
    public function footerContactForm(): Response
    {
        $form = $this->createForm(ContactType::class);

        return $this->render('public/_footer_contact_form.html.twig', [
            'contactForm' => $form->createView(),
        ]);
    }


    #[Route('/politique-de-confidentialite', name: 'app_public_politique_confidentialite')]
    public function politiqueConfidentialite(): Response
    {
        return $this->render('public/politique_confidentialite.html.twig');
    }

    #[Route('/mentions-legales', name: 'app_public_mentions_legales')]
    public function mentionsLegales(): Response
    {
        return $this->render('public/mentions_legales.html.twig');
    }

    #[Route('/cgu', name: 'app_public_cgu')]
    public function cgu(): Response
    {
        return $this->render('public/cgu.html.twig');
    }

    #[Route('/cgv', name: 'app_public_cgv')]
    public function cgv(): Response
    {
        return $this->render('public/cgv.html.twig');
    }
}
