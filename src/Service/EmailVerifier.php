<?php

// src/Service/EmailVerifier.php
namespace App\Service;

use App\Entity\Utilisateur;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\RouterInterface;
use App\Security\PublicSecurityUrl;

final class EmailVerifier
{
    public function __construct(
        private VerifyEmailHelperInterface $verifyEmailHelper,
        private MailerInterface $mailer,
        private RouterInterface $router,
    ) {}

    public function sendEmailConfirmation(Utilisateur $user): void
    {
        $context = $this->router->getContext();
        $canonical = clone $context;
        $canonical->setHost((string) parse_url(PublicSecurityUrl::ORIGIN, PHP_URL_HOST));
        $canonical->setScheme('https');
        $canonical->setHttpsPort(443);
        $this->router->setContext($canonical);
        try {
            $signature = $this->verifyEmailHelper->generateSignature(
            'app_verify_email',
            (string) $user->getId(),          // ← string attendu
            $user->getEmail(),
            ['id' => $user->getId()]          // param de la route
            );
        } finally {
            $this->router->setContext($context);
        }

        $from = new Address('no-reply@philipfreres.com', 'Philip Frères');

        $email = (new TemplatedEmail())
            ->from($from)
            ->sender($from)                   // header Sender
            ->returnPath('no-reply@philipfreres.com')
            ->to($user->getEmail())
            ->subject('Confirmez votre adresse e-mail')
            ->htmlTemplate('emails/verifieMail.html.twig')
            ->context([
                'verifyUrl'             => $signature->getSignedUrl(),
                'expiresAtMessageKey'   => $signature->getExpirationMessageKey(),
                'expiresAtMessageData'  => $signature->getExpirationMessageData(),
                'user'                  => $user,
            ]);

        $this->mailer->send($email);
    }
}
