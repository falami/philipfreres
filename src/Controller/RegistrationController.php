<?php

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Service\EmailVerifier;
use App\Form\RegistrationFormType;
use App\Service\Email\MailerManager;
use App\Service\Utilisateur\UtilisateurManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class RegistrationController extends AbstractController
{
    public function __construct(
        private readonly RateLimiterFactoryInterface $registrationIpLimiter,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.secret%')] private readonly string $secret,
    ) {}

    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        EmailVerifier $emailVerifier,
        MailerManager $mailerManager,
        EntityManagerInterface $em,
        UtilisateurManager $utilisateurManager,
        UserPasswordHasherInterface $passwordHasher,
    ): Response {
        $user = new Utilisateur();

        $userPrincipal = $utilisateurManager->getRepository()->find(1);
        if ($userPrincipal) {
            $user->setCreateur($userPrincipal);
        }

        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->render('registration/register.html.twig', [
                'registrationForm' => $form->createView(),
            ]);
        }

        $email = (string) $form->get('email')->getData();

        if (!$this->registrationIpLimiter->create(hash_hmac('sha256', (string) $request->getClientIp(), $this->secret))->consume()->isAccepted()) {
            $this->addFlash('warning', 'Trop de demandes. Veuillez patienter avant de réessayer.');
            return $this->redirectToRoute('app_login');
        }

        /** @var Utilisateur|null $existing */
        $existing = $utilisateurManager->getRepository()->findOneBy(['email' => $email]);

        // ===================================
        // CAS A : email déjà en base
        // ===================================
        if ($existing) {
            // A public registration must never overwrite an existing account, even unverified.
            $this->addFlash('info', 'Si vous disposez déjà d’un compte, connectez-vous ou demandez un nouveau mot de passe.');
            return $this->redirectToRoute('app_login');
        }

        // ===================================
        // CAS B : nouvel utilisateur
        // ===================================
        $plainPassword = (string) $form->get('password')->getData();
        $user->setPassword($passwordHasher->hashPassword($user, $plainPassword));

        $user->setPrenom((string) $form->get('prenom')->getData());
        $user->setNom((string) $form->get('nom')->getData());

        $user->setDateConsentementRgpd(new \DateTimeImmutable());
        $user->setConsentementRgpd(true);
        $user->setNewsletter((bool) $form->get('newsletter')->getData());

        $user->setIsVerified(false);
        $user->setRoles(['ROLE_USER']);
        $user->setMailBienvenue(true);

        $user->setCouleur($user->getCouleur() ?: '#000000');

        if ($userPrincipal) {
            $user->setCreateur($userPrincipal);
        }

        $em->persist($user);
        $em->flush();

        try {
            $emailVerifier->sendEmailConfirmation($user);
            $this->addFlash('success', 'Un e-mail de confirmation vous a été envoyé. Vous pouvez maintenant vous connecter.');
        } catch (\Throwable) {
            $this->logger->error('Registration confirmation email delivery failed.', ['user_id' => $user->getId()]);
            $this->addFlash('warning', 'Votre compte a été créé, mais l’e-mail n’a pas pu être envoyé. Réessayez de vous connecter dans quelques minutes.');
        }
        try {
            $mailerManager->sendMailContext(
                'no-reply@philipfreres.com',
                'no-reply@philipfreres.com',
                'Création d\'un nouveau compte : ' . $user->getEmail(),
                'emails/creationCompte.html.twig',
                ['adherent' => $user]
            );
        } catch (\Throwable) {
            $this->logger->error('Registration notification delivery failed.', ['user_id' => $user->getId()]);
        }
        return $this->redirectToRoute('app_login');
    }
}
