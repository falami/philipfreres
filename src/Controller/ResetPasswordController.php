<?php
// src/Controller/ResetPasswordController.php

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Form\ResetPasswordFormType;
use App\Form\ResetPasswordRequestFormType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response, RedirectResponse};
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[Route('/reset-password')]
final class ResetPasswordController extends AbstractController
{
    private const TOKEN_TTL = '+1 hour'; // ✅ change ici si tu veux 30 min, 2h, etc.

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'app_forgot_password_request', methods: ['GET', 'POST'])]
    public function request(Request $request, MailerInterface $mailer): Response
    {
        $form = $this->createForm(ResetPasswordRequestFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $email = (string) $form->get('email')->getData();
            return $this->processSendingPasswordResetEmail($email, $mailer);
        }

        return $this->render('reset_password/request.html.twig', [
            'requestForm' => $form->createView(),
            'ttlHuman' => '1 heure',
        ]);
    }

    #[Route('/check-email', name: 'app_check_email', methods: ['GET'])]
    public function checkEmail(): Response
    {
        return $this->render('reset_password/check_email.html.twig', [
            'ttlHuman' => '1 heure',
        ]);
    }

    #[Route('/reset/{token}', name: 'app_reset_password', methods: ['GET', 'POST'])]
    public function reset(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        string $token
    ): Response {
        /** @var Utilisateur|null $user */
        $user = $this->em->getRepository(Utilisateur::class)->findOneBy(['resetToken' => $token]);
        $expiresAt = $user?->getResetTokenExpiresAt();

        if (!$user || !$expiresAt || $expiresAt <= new \DateTimeImmutable()) {
            $this->addFlash('danger', 'Lien invalide ou expiré. Merci de refaire une demande.');
            return $this->redirectToRoute('app_forgot_password_request');
        }

        $form = $this->createForm(ResetPasswordFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $newPassword = (string) $form->get('password')->getData();

            $user->setPassword($passwordHasher->hashPassword($user, $newPassword));

            // ✅ invalide le token
            $user->setResetToken(null);
            $user->setResetTokenExpiresAt(null);

            // optionnel si tu veux vérifier automatiquement :
            // $user->setIsVerified(true);

            $this->em->flush();

            $this->addFlash('success', 'Mot de passe mis à jour. Vous pouvez vous connecter.');
            return $this->redirectToRoute('app_login');
        }

        return $this->render('reset_password/reset.html.twig', [
            'resetForm' => $form->createView(),
            'expiresAt' => $expiresAt,
        ]);
    }

    private function processSendingPasswordResetEmail(string $emailFormData, MailerInterface $mailer): RedirectResponse
    {
        /** @var Utilisateur|null $user */
        $user = $this->em->getRepository(Utilisateur::class)->findOneBy(['email' => $emailFormData]);

        // ✅ anti-enumération : même réponse si email inconnu
        if (!$user) {
            return $this->redirectToRoute('app_check_email');
        }

        // ✅ génère token (64 chars hex)
        $token = bin2hex(random_bytes(32));
        $expiresAt = new \DateTimeImmutable(self::TOKEN_TTL);

        $user->setResetToken($token);
        $user->setResetTokenExpiresAt($expiresAt);
        $this->em->flush();

        $resetUrl = $this->generateUrl('app_reset_password', [
            'token' => $token,
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        $email = (new TemplatedEmail())
            ->from(new Address('no-reply@jeroensnow.fr', 'Jeroensnow'))
            ->to(new Address($user->getEmail(), trim(($user->getPrenom() ?? '') . ' ' . ($user->getNom() ?? ''))))
            ->subject('Réinitialisation de votre mot de passe')
            ->htmlTemplate('reset_password/email.html.twig')
            ->context([
                'resetTokenUrl' => $resetUrl,
                'expiresAt' => $expiresAt,
                'ttlHuman' => '1 heure',
                'user' => $user,
            ]);

        $mailer->send($email);

        return $this->redirectToRoute('app_check_email');
    }
}
