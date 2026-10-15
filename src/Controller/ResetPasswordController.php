<?php
// src/Controller/ResetPasswordController.php

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Security\PasswordResetTokens;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
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

#[Route('/reset-password')]
final class ResetPasswordController extends AbstractController
{
    private const TOKEN_TTL = '+1 hour';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RateLimiterFactoryInterface $passwordResetLimiter,
        private readonly RateLimiterFactoryInterface $passwordResetIpLimiter,
        private readonly LoggerInterface $logger,
        private readonly \App\Security\PublicSecurityUrl $publicUrl,
        #[Autowire('%kernel.secret%')] private readonly string $secret,
    ) {}

    #[Route('', name: 'app_forgot_password_request', methods: ['GET', 'POST'])]
    public function request(Request $request, MailerInterface $mailer): Response
    {
        $form = $this->createForm(ResetPasswordRequestFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $email = trim((string) $form->get('email')->getData());
            $accountKey = hash_hmac('sha256', mb_strtolower($email), $this->secret);
            $ipKey = hash_hmac('sha256', (string) $request->getClientIp(), $this->secret);
            // Consume both buckets even for nonexistent accounts. Always show the same response.
            $accountAllowed = $this->passwordResetLimiter->create($accountKey)->consume()->isAccepted();
            $ipAllowed = $this->passwordResetIpLimiter->create($ipKey)->consume()->isAccepted();
            if (!$accountAllowed || !$ipAllowed) return $this->redirectToRoute('app_check_email');
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
        $user = preg_match('/^[a-zA-Z0-9_-]{32,128}$/D', $token)
            ? $this->em->getRepository(Utilisateur::class)->findOneBy(['resetToken' => PasswordResetTokens::digest($token)])
            : null;
        $expiresAt = $user?->getResetTokenExpiresAt();

        if (!$user || $user->isBannir() || $user->isDesactiverTemporairement() || !$expiresAt || $expiresAt <= new \DateTimeImmutable()) {
            $this->addFlash('danger', 'Lien invalide ou expiré. Merci de refaire une demande.');
            return $this->redirectToRoute('app_forgot_password_request');
        }

        $form = $this->createForm(ResetPasswordFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $newPassword = (string) $form->get('password')->getData();

            // Atomic single use: two simultaneous form submissions cannot reuse the link.
            $updated = $this->em->getConnection()->executeStatement(
                'UPDATE utilisateur SET password = :password, reset_token = NULL, reset_token_expires_at = NULL, security_version = security_version + 1 WHERE id = :id AND reset_token = :token AND reset_token_expires_at > :now',
                ['password' => $passwordHasher->hashPassword($user, $newPassword), 'id' => $user->getId(), 'token' => PasswordResetTokens::digest($token), 'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')]
            );
            if ($updated !== 1) {
                $this->addFlash('danger', 'Ce lien a déjà été utilisé ou a expiré.');
                return $this->redirectToRoute('app_forgot_password_request');
            }
            $this->em->refresh($user);

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
        if (!$user || $user->isBannir() || $user->isDesactiverTemporairement()) {
            return $this->redirectToRoute('app_check_email');
        }

        $token = PasswordResetTokens::issue($user, self::TOKEN_TTL);
        $expiresAt = $user->getResetTokenExpiresAt();
        $this->em->flush();

        $resetUrl = $this->publicUrl->reset($token);

        $email = (new TemplatedEmail())
            ->from(new Address('no-reply@philipfreres.com', 'Philip Frères'))
            ->to(new Address($user->getEmail(), trim(($user->getPrenom() ?? '') . ' ' . ($user->getNom() ?? ''))))
            ->subject('Réinitialisation de votre mot de passe')
            ->htmlTemplate('reset_password/email.html.twig')
            ->context([
                'resetTokenUrl' => $resetUrl,
                'expiresAt' => $expiresAt,
                'ttlHuman' => '1 heure',
                'user' => $user,
            ]);

        try {
            $mailer->send($email);
        } catch (\Throwable) {
            // Never expose account existence, reset links, SMTP credentials or mail content.
            $this->logger->error('Password reset email delivery failed.', ['user_id' => $user->getId()]);
        }

        return $this->redirectToRoute('app_check_email');
    }
}
