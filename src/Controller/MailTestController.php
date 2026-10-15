<?php
// src/Controller/MailTestController.php
namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\HttpFoundation\Response;

class MailTestController extends AbstractController
{
    // Kept callable for compatibility, but never expose a public mail relay/debug exception.
    #[Route('/_mail-test', name: 'app_mail_test', methods: ['POST'])]
    #[\Symfony\Component\Security\Http\Attribute\IsGranted('ROLE_SUPER_ADMIN')]
    public function __invoke(MailerInterface $mailer, \Symfony\Component\HttpFoundation\Request $request): Response
    {
        if ($this->getParameter('kernel.environment') === 'prod') throw $this->createNotFoundException();
        if (!$this->isCsrfTokenValid('mail_test', $request->request->getString('_token'))) throw $this->createAccessDeniedException();
        try {
            $email = (new Email())
                ->from('no-reply@wkformation.fr')   // même adresse que le compte SMTP
                ->to('jeroen30@hotmail.fr')      // ou ton adresse perso
                ->subject('Test Symfony Mailer')
                ->text('Hello, ceci est un test Symfony Mailer (sync).')
                ->html('<p>Hello, ceci est un <strong>test</strong> Symfony Mailer (sync).</p>');

            $mailer->send($email);

            return new Response('OK: email envoyé (sync)');
        } catch (\Throwable) {
            return new Response('Échec de l’envoi. Vérifiez la configuration du transport.', 502);
        }
    }
}
