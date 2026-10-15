<?php

declare(strict_types=1);

namespace App\Security\TwoFactor;

use App\Entity\Utilisateur;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

/** Challenges live only in the password-verified, exclusively locked server session. */
final class EmailChallenge
{
    public const KEY = '_login_email_challenge';
    public const LIFETIME = 600;
    public const CHALLENGE_LIFETIME = 1800;
    public const MAX_ATTEMPTS = 5;
    public const RESEND_DELAY = 60;

    public function __construct(
        private readonly RequestStack $requests,
        private readonly ClockInterface $clock,
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.secret%')] private readonly string $secret,
        private readonly RateLimiterFactoryInterface $twoFactorSendLimiter,
        private readonly RateLimiterFactoryInterface $twoFactorVerifyLimiter,
        private readonly RateLimiterFactoryInterface $twoFactorIpLimiter,
    ) {}

    public function begin(Utilisateur $user): void
    {
        $this->requests->getSession()->set(self::KEY, [
            'identity' => $this->identity($user), 'started' => $this->now(),
            'sent' => 0, 'expires' => 0, 'attempts' => 0, 'hash' => '', 'nonce' => '',
        ]);
    }

    public function send(Utilisateur $user): string
    {
        $session = $this->requests->getSession();
        $state = $session->get(self::KEY, []);
        if (!$this->isCurrent($state, $user)) {
            return 'Cette demande a expiré. Revenez à la connexion pour recommencer.';
        }
        if ($state['sent'] > $this->now() - self::RESEND_DELAY) {
            return 'Veuillez patienter une minute entre deux envois.';
        }
        if (!$this->twoFactorSendLimiter->create($this->accountKey($user))->consume()->isAccepted()
            || !$this->twoFactorIpLimiter->create($this->ipKey('send'))->consume()->isAccepted()) {
            return 'Trop de demandes de code. Veuillez patienter quinze minutes avant de réessayer.';
        }

        $code = sprintf('%06d', random_int(0, 999999));
        $state['nonce'] = bin2hex(random_bytes(32));
        $state['hash'] = hash_hmac('sha256', $state['nonce'] . $code, $this->secret);
        $state['sent'] = $this->now();
        $state['expires'] = min($this->now() + self::LIFETIME, $state['started'] + self::CHALLENGE_LIFETIME);
        $state['attempts'] = 0;
        $session->set(self::KEY, $state); // Resending invalidates the previous code immediately.
        try {
            $this->mailer->send((new TemplatedEmail())
                ->from(new Address('no-reply@philipfreres.com', 'Philip Frères'))
                ->to((string) $user->getEmail())
                ->subject('Votre code de connexion — Philip Frères')
                ->htmlTemplate('security/email/login_code.html.twig')
                ->textTemplate('security/email/login_code.txt.twig')
                ->context(['code' => $code]));
        } catch (\Throwable) {
            $state['hash'] = '';
            $state['expires'] = 0;
            $session->set(self::KEY, $state);
            // Transport exception messages can contain credentials or the actual code.
            $this->logger->error('Login verification email delivery failed.', ['user_id' => $user->getId()]);
            return 'L’e-mail n’a pas pu être envoyé. Réessayez dans une minute. Votre accès reste protégé.';
        }

        return '';
    }

    public function verify(Utilisateur $user, #[\SensitiveParameter] string $code): bool
    {
        $session = $this->requests->getSession();
        $state = $session->get(self::KEY, []);
        if (!$this->twoFactorVerifyLimiter->create($this->accountKey($user))->consume()->isAccepted()
            || !$this->twoFactorIpLimiter->create($this->ipKey('verify'))->consume()->isAccepted()) {
            throw new CustomUserMessageAuthenticationException('Trop de tentatives. Veuillez patienter quinze minutes avant de réessayer.');
        }
        if (!$this->isCurrent($state, $user) || !$state['hash'] || $state['expires'] <= $this->now()) {
            throw new CustomUserMessageAuthenticationException('Ce code a expiré ou n’est plus disponible. Demandez un nouveau code.');
        }
        if ($state['attempts'] >= self::MAX_ATTEMPTS) {
            throw new CustomUserMessageAuthenticationException('Ce code est bloqué après cinq essais. Demandez un nouveau code.');
        }
        ++$state['attempts'];
        $session->set(self::KEY, $state);
        $code = trim($code);
        if (!preg_match('/^[0-9]{6}$/D', $code)
            || !hash_equals($state['hash'], hash_hmac('sha256', $state['nonce'] . $code, $this->secret))) {
            throw new CustomUserMessageAuthenticationException('Le code saisi est incorrect. Vérifiez les six chiffres reçus par e-mail.');
        }
        $session->remove(self::KEY);
        $this->logger->info('Email login verification completed.', ['user_id' => $user->getId()]);

        return true;
    }

    public function maskedEmail(Utilisateur $user): string
    {
        [$local, $domain] = array_pad(explode('@', (string) $user->getEmail(), 2), 2, '');
        return mb_substr($local, 0, 1) . '•••@' . $domain;
    }

    public function status(Utilisateur $user): array
    {
        $state = $this->requests->getSession()->get(self::KEY, []);
        $current = $this->isCurrent($state, $user);
        return [
            'resendAvailableIn' => max(0, ($state['sent'] ?? 0) + self::RESEND_DELAY - $this->now()),
            'codeExpiresIn' => $current ? max(0, ($state['expires'] ?? 0) - $this->now()) : 0,
            'challengeExpired' => !$current,
        ];
    }

    private function isCurrent(array $state, Utilisateur $user): bool
    {
        return isset($state['identity'], $state['started'])
            && !$user->isBannir() && !$user->isDesactiverTemporairement()
            && hash_equals($state['identity'], $this->identity($user))
            && $state['started'] > $this->now() - self::CHALLENGE_LIFETIME;
    }

    private function identity(Utilisateur $user): string
    {
        return hash_hmac('sha256', $user->getId() . ':' . $user->getEmail() . ':' . $user->getPassword() . ':' . $user->getSecurityVersion(), $this->secret);
    }

    private function accountKey(Utilisateur $user): string
    {
        return hash_hmac('sha256', 'account:' . $user->getId(), $this->secret);
    }

    private function ipKey(string $operation): string
    {
        return hash_hmac('sha256', $operation . ':' . $this->requests->getMainRequest()?->getClientIp(), $this->secret);
    }

    private function now(): int { return $this->clock->now()->getTimestamp(); }
}
