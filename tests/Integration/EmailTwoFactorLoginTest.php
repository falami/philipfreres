<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Entite;
use App\Entity\Utilisateur;
use App\Entity\UtilisateurEntite;
use App\Security\TwoFactor\EmailChallenge;
use App\Service\EmailVerifier;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Http\RememberMe\RememberMeDetails;

/** Real firewall requests against synthetic accounts in a fresh, isolated SQLite database. */
final class EmailTwoFactorLoginTest extends KernelTestCase
{
    private const PASSWORD = 'Test-password-47!';
    private const REMEMBER_COOKIE = 'PHILIPFRERES_REMEMBER_V2';

    private KernelBrowser $client;
    private array $previousEnvironment = [];
    /** @var list<TemplatedEmail> */
    private array $emails = [];
    private string $email;
    private int $userId;
    private int $tenantId;
    private bool $deliveryFails = false;

    protected function setUp(): void
    {
        parent::setUp();
        // Set before booting the kernel: these tests cannot connect to the local application database or SMTP.
        foreach (['DATABASE_URL' => 'sqlite:///:memory:', 'MAILER_DSN' => 'null://null'] as $name => $value) {
            $this->previousEnvironment[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null];
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
        self::bootKernel();
        $em = $this->entityManager();
        self::assertSame('pdo_sqlite', $em->getConnection()->getParams()['driver']);
        self::assertTrue($em->getConnection()->getParams()['memory'] ?? false);
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
        self::getContainer()->get('app.security.rate_limiter.cache')->clear();
        self::getContainer()->get('twig')->addGlobal('entite', null);

        $this->email = 'security-'.bin2hex(random_bytes(8)).'@example.test';
        $user = (new Utilisateur())->setEmail($this->email)->setPrenom('Camille')->setNom('Test');
        $user->setPassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $em->persist($user);
        $em->flush();
        $tenant = (new Entite())->setNom('Entité de test sécurité')->setCreateur($user)->setPublic(false)->setIsActive(true);
        $em->persist($tenant);
        $em->flush();
        $user->setEntite($tenant);
        $membership = (new UtilisateurEntite())->setUtilisateur($user)->setEntite($tenant)->setCreateur($user)->setRoles([UtilisateurEntite::TENANT_ADMIN]);
        $em->persist($membership);
        $em->flush();
        $this->userId = $user->getId();
        $this->tenantId = $tenant->getId();

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(function ($email): void {
            if ($this->deliveryFails) {
                throw new TransportException('Synthetic SMTP failure; no external server is contacted.');
            }
            self::assertInstanceOf(TemplatedEmail::class, $email);
            $this->emails[] = $email;
        });
        self::getContainer()->set(MailerInterface::class, $mailer);
        $this->client = new KernelBrowser(self::$kernel);
        $this->client->disableReboot();
        $this->client->catchExceptions(true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach ($this->previousEnvironment as $name => $values) {
            foreach (['ENV', 'SERVER'] as $index => $scope) {
                if ($values[$index] === null) {
                    unset($GLOBALS['_'.$scope][$name]);
                } else {
                    $GLOBALS['_'.$scope][$name] = $values[$index];
                }
            }
        }
    }

    public function testPasswordAloneNeverGrantsPrivateAccessOrRememberCookie(): void
    {
        $this->passwordLogin();
        $this->assertRedirectPath('/2fa');
        self::assertCount(1, $this->emails);
        self::assertSame($this->email, $this->emails[0]->getTo()[0]->getAddress());
        self::assertMatchesRegularExpression('/^[0-9]{6}$/D', $this->latestCode());
        $state = $this->client->getSession()->get(EmailChallenge::KEY);
        self::assertIsArray($state);
        self::assertStringNotContainsString('"'.$this->latestCode().'"', json_encode($state, JSON_THROW_ON_ERROR));
        self::assertNull($this->rememberCookie());

        foreach (['/fr/workspace', '/fr/compte/securite', '/fr/administrateur/'.$this->tenantId.'/carburant/dashboard', '/fr/login'] as $path) {
            $this->client->request('GET', $path);
            $this->assertRedirectPath('/2fa');
            self::assertNull($this->rememberCookie());
        }
        $page = $this->client->request('GET', '/2fa');
        $this->assertStatus(200);
        self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertCount(1, $this->emails, 'Reloading the code page must not send another code.');
        self::assertSame(1, $page->filter('input[autocomplete="one-time-code"]')->count());
    }

    public function testCompletedVerificationIssuesRememberCookieAndResumesWithoutNewEmail(): void
    {
        $this->passwordLogin();
        $this->completeVerification();
        self::assertFalse($this->client->getSession()->has(EmailChallenge::KEY));
        $cookie = $this->rememberCookie();
        self::assertNotNull($cookie);
        self::assertTrue($cookie->isHttpOnly());
        self::assertGreaterThan(time() + 89 * 86400, $cookie->getExpiresTime());
        self::assertLessThanOrEqual(time() + 90 * 86400 + 5, $cookie->getExpiresTime());

        $this->forgetBrowserSession();
        $this->client->request('GET', '/fr/compte/securite');
        $this->assertStatus(200);
        self::assertCount(1, $this->emails, 'A completed, remembered browser must not need another email code.');
    }

    public function testUncheckedRememberOptionDoesNotPersistBrowserAuthentication(): void
    {
        $this->passwordLogin(remember: false);
        $this->completeVerification();
        self::assertNull($this->rememberCookie());
        $this->forgetBrowserSession();
        $this->client->request('GET', '/fr/compte/securite');
        $this->assertRedirectPath('/fr/login');
    }

    public function testRememberedDeviceStoresOnlyHashAndRotatesAfterRestoringSession(): void
    {
        $this->passwordLogin();
        $this->completeVerification();
        $oldCookie = clone $this->rememberCookie();
        [$series, $validator] = $this->rememberedDeviceParts();
        $connection = $this->entityManager()->getConnection();
        $row = $connection->fetchAssociative('SELECT * FROM remembered_device WHERE series = ?', [$series]);
        self::assertIsArray($row);
        self::assertSame($this->email, $row['username']);
        self::assertSame(hash('sha256', $validator), $row['validator_hash']);
        self::assertStringNotContainsString($validator, json_encode($row, JSON_THROW_ON_ERROR));

        $connection->update('remembered_device', ['last_used' => (new \DateTimeImmutable('-2 minutes', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s')], ['series' => $series]);
        $this->forgetBrowserSession();
        $this->client->request('GET', '/fr/compte/securite');
        $this->assertStatus(200);
        [$newSeries, $newValidator] = $this->rememberedDeviceParts();
        self::assertSame($series, $newSeries);
        self::assertNotSame($validator, $newValidator);
        $rotated = $connection->fetchAssociative('SELECT * FROM remembered_device WHERE series = ?', [$series]);
        self::assertSame(hash('sha256', $newValidator), $rotated['validator_hash']);
        self::assertSame(hash('sha256', $validator), $rotated['previous_validator_hash']);
        self::assertCount(1, $this->emails);

        $connection->update('remembered_device', ['previous_valid_until' => (new \DateTimeImmutable('-1 second', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s')], ['series' => $series]);
        $this->client->getCookieJar()->clear();
        $this->client->getCookieJar()->set($oldCookie);
        $this->client->request('GET', '/fr/compte/securite');
        $this->assertRedirectPath('/fr/login');
    }

    public function testRememberedDeviceExpiresAfterNinetyDaysEvenWhenRecentlyUsed(): void
    {
        $this->passwordLogin();
        $this->completeVerification();
        [$series] = $this->rememberedDeviceParts();
        $this->entityManager()->getConnection()->update('remembered_device', [
            'created_at' => (new \DateTimeImmutable('-91 days', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
            'last_used' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
        ], ['series' => $series]);
        $this->forgetBrowserSession();
        $this->client->request('GET', '/fr/compte/securite');
        $this->assertRedirectPath('/fr/login');
    }

    public function testWrongPasswordAndPasswordThrottlingNeverSendCode(): void
    {
        for ($attempt = 0; $attempt < 6; ++$attempt) {
            $this->passwordLogin('incorrect');
            $this->assertRedirectPath('/fr/login');
        }
        $this->passwordLogin();
        $this->assertRedirectPath('/fr/login');
        self::assertCount(0, $this->emails);
        self::assertNull($this->rememberCookie());
    }

    public function testInvalidLoginCsrfCannotStartEmailChallenge(): void
    {
        $this->client->request('GET', '/fr/login');
        $this->client->request('POST', '/fr/login', ['_username' => $this->email, '_password' => self::PASSWORD, '_csrf_token' => 'forged']);
        $this->assertRedirectPath('/fr/login');
        self::assertCount(0, $this->emails);
    }

    public function testInvalidCodeAndInvalidCsrfKeepAccountLocked(): void
    {
        $this->passwordLogin();
        $this->submitCode('invalid');
        $this->assertRedirectPath('/2fa');
        $this->client->request('POST', '/2fa_check', ['_auth_code' => $this->latestCode(), '_csrf_token' => 'forged']);
        $this->assertRedirectPath('/2fa');
        self::assertNull($this->rememberCookie());
        $this->client->request('GET', '/fr/compte/securite');
        $this->assertRedirectPath('/2fa');
    }

    public function testFiveWrongCodesLockChallengeEvenWhenNextCodeIsCorrect(): void
    {
        $this->passwordLogin();
        for ($attempt = 0; $attempt < EmailChallenge::MAX_ATTEMPTS; ++$attempt) {
            $this->submitCode('invalid');
            $this->assertRedirectPath('/2fa');
        }
        $this->submitCode($this->latestCode());
        $this->assertRedirectPath('/2fa');
        self::assertNull($this->rememberCookie());
        $this->client->request('GET', '/fr/compte/securite');
        $this->assertRedirectPath('/2fa');
    }

    public function testExpiredCodeCannotCompleteAuthentication(): void
    {
        $this->passwordLogin();
        $this->changeChallenge(['expires' => time() - 1]);
        $this->submitCode($this->latestCode());
        $this->assertRedirectPath('/2fa');
        self::assertNull($this->rememberCookie());
    }

    public function testCodeFromOneBrowserCannotAuthenticateAnotherBrowser(): void
    {
        $this->passwordLogin();
        $stolenCode = $this->latestCode();
        $this->client->getCookieJar()->clear();
        $this->client->request('POST', '/2fa_check', ['_auth_code' => $stolenCode]);
        self::assertNull($this->rememberCookie());
        $this->client->request('GET', '/fr/compte/securite');
        $this->assertRedirectPath('/fr/login');
    }

    public function testConsumedCodeCannotCreateANewAuthenticatedSession(): void
    {
        $this->passwordLogin();
        $usedCode = $this->latestCode();
        $this->completeVerification();
        self::assertFalse($this->client->getSession()->has(EmailChallenge::KEY));
        $this->client->getCookieJar()->clear();
        $this->client->request('POST', '/2fa_check', ['_auth_code' => $usedCode]);
        self::assertNull($this->rememberCookie());
        $this->client->request('GET', '/fr/compte/securite');
        $this->assertRedirectPath('/fr/login');
    }

    public function testStartingNewBrowserSessionsDoesNotResetAccountSendLimit(): void
    {
        for ($attempt = 0; $attempt < 6; ++$attempt) {
            $this->client->getCookieJar()->clear();
            $this->passwordLogin();
            $this->assertRedirectPath('/2fa');
        }
        self::assertCount(5, $this->emails);
        self::assertNull($this->rememberCookie());
        $this->client->request('GET', '/fr/compte/securite');
        $this->assertRedirectPath('/2fa');
    }

    public function testStartingNewBrowserSessionsDoesNotResetCodeGuessingLimit(): void
    {
        for ($session = 0; $session < 2; ++$session) {
            $this->client->getCookieJar()->clear();
            // A fresh address avoids the separate password/IP bucket, exercising the account-wide OTP limit.
            $this->client->setServerParameter('REMOTE_ADDR', '198.51.100.'.(10 + $session));
            $this->passwordLogin();
            for ($attempt = 0; $attempt < EmailChallenge::MAX_ATTEMPTS; ++$attempt) {
                $this->submitCode('invalid');
                $this->assertRedirectPath('/2fa');
            }
        }
        $this->client->getCookieJar()->clear();
        $this->client->setServerParameter('REMOTE_ADDR', '198.51.100.12');
        $this->passwordLogin();
        $this->submitCode($this->latestCode());
        $this->assertRedirectPath('/2fa');
        self::assertNull($this->rememberCookie());
        $this->client->request('GET', '/fr/compte/securite');
        $this->assertRedirectPath('/2fa');
    }

    public function testResendingRequiresCsrfAndCooldownAndReplacesPreviousChallenge(): void
    {
        $this->passwordLogin();
        $oldHash = $this->client->getSession()->get(EmailChallenge::KEY)['hash'];
        $this->changeChallenge(['sent' => time() - 61]);
        $this->client->request('POST', '/fr/2fa/resend', ['_token' => 'forged']);
        $this->assertRedirectPath('/2fa');
        self::assertCount(1, $this->emails);
        $this->resendCode();
        self::assertCount(2, $this->emails);
        self::assertNotSame($oldHash, $this->client->getSession()->get(EmailChallenge::KEY)['hash']);
        $this->resendCode();
        self::assertCount(2, $this->emails, 'An immediate resend must be throttled.');
        $this->completeVerification();
    }

    public function testMailDeliveryFailureDoesNotPermitAuthentication(): void
    {
        $this->deliveryFails = true;
        $this->passwordLogin();
        $this->assertRedirectPath('/2fa');
        self::assertCount(0, $this->emails);
        $this->submitCode('000000');
        $this->assertRedirectPath('/2fa');
        self::assertNull($this->rememberCookie());
        $this->client->request('GET', '/fr/compte/securite');
        $this->assertRedirectPath('/2fa');
    }

    public function testLogoutRequiresValidCsrfAndClearsRememberedBrowser(): void
    {
        $this->passwordLogin();
        $this->completeVerification();
        $copiedCookie = clone $this->rememberCookie();
        $this->client->request('POST', '/fr/logout', ['_csrf_token' => 'forged']);
        $this->assertStatus(403);
        $page = $this->client->request('GET', '/fr/compte/securite');
        $this->assertStatus(200);
        $this->client->submit($page->filter('form[action="/fr/logout"]')->first()->form());
        $this->assertRedirectPath('/fr/login');
        self::assertNull($this->rememberCookie());
        $this->client->request('GET', '/fr/compte/securite');
        $this->assertRedirectPath('/fr/login');
        $this->client->getCookieJar()->clear();
        $this->client->getCookieJar()->set($copiedCookie);
        $this->client->request('GET', '/fr/compte/securite');
        $this->assertRedirectPath('/fr/login');
    }

    public function testRevocationInvalidatesActiveSessionAndCopiedRememberCookie(): void
    {
        $this->passwordLogin();
        $this->completeVerification();
        $oldCookie = clone $this->rememberCookie();
        $oldVersion = $this->user()->getSecurityVersion();
        $page = $this->client->request('GET', '/fr/compte/securite');
        $this->assertStatus(200);
        $this->client->submit($page->filter('form[action="/fr/compte/securite/revoquer"]')->form());
        $this->assertRedirectPath('/fr/login');
        self::assertNotSame($oldVersion, $this->user()->getSecurityVersion());
        self::assertNull($this->rememberCookie());
        $this->client->getCookieJar()->clear();
        $this->client->getCookieJar()->set($oldCookie);
        $this->client->request('GET', '/fr/compte/securite');
        $this->assertRedirectPath('/fr/login');
    }

    public function testServerRevocationInvalidatesAnAlreadyOpenSession(): void
    {
        $this->passwordLogin();
        $this->completeVerification();
        $user = $this->user();
        $user->revokeSessions();
        $this->entityManager()->flush();
        $this->entityManager()->clear();
        $this->client->request('GET', '/fr/compte/securite');
        $this->assertRedirectPath('/fr/login');
    }

    public function testPasswordChangeInvalidatesPreviouslyRememberedBrowser(): void
    {
        $this->passwordLogin();
        $this->completeVerification();
        $user = $this->user();
        $user->setPassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'Changed-password-88!'));
        $this->entityManager()->flush();
        $this->entityManager()->clear();
        $this->forgetBrowserSession();
        $this->client->request('GET', '/fr/compte/securite');
        $this->assertRedirectPath('/fr/login');
    }

    public function testBannedAccountCannotStartAuthentication(): void
    {
        $this->user()->setBannir(true);
        $this->entityManager()->flush();
        $this->entityManager()->clear();
        $this->passwordLogin();
        $this->assertRedirectPath('/fr/login');
        self::assertCount(0, $this->emails);
    }

    public function testDisablingAccountRejectsItsRememberedBrowser(): void
    {
        $this->passwordLogin();
        $this->completeVerification();
        $this->user()->setDesactiverTemporairement(true);
        $this->entityManager()->flush();
        $this->entityManager()->clear();
        $this->forgetBrowserSession();
        $this->client->request('GET', '/fr/compte/securite');
        $this->assertRedirectPath('/fr/login');
    }

    public function testBanningAccountDuringChallengeRejectsEvenItsCorrectCode(): void
    {
        $this->passwordLogin();
        $page = $this->client->request('GET', '/2fa');
        $form = $page->filter('form[action="/2fa_check"]')->form();
        $form['_auth_code'] = $this->latestCode();
        $this->user()->setBannir(true);
        $this->entityManager()->flush();
        $this->entityManager()->clear();
        $this->client->submit($form);
        $this->assertRedirectPath('/2fa');
        self::assertNull($this->rememberCookie());
        $this->client->request('GET', '/fr/compte/securite');
        $this->assertRedirectPath('/2fa');
    }

    public function testPasswordResetLinkIsHashedSingleUseAndRevokesRememberedAccess(): void
    {
        $this->passwordLogin();
        $this->completeVerification();
        $oldCookie = clone $this->rememberCookie();
        $oldSession = $this->client->getCookieJar()->get($this->client->getSession()->getName());
        self::assertNotNull($oldSession);
        $oldSession = clone $oldSession;
        $oldVersion = $this->user()->getSecurityVersion();
        $this->client->getCookieJar()->clear();

        $this->requestPasswordReset($this->email);
        $this->assertRedirectPath('/fr/reset-password/check-email');
        self::assertCount(2, $this->emails);
        $url = $this->emails[1]->getContext()['resetTokenUrl'];
        $path = parse_url($url, PHP_URL_PATH);
        $rawToken = basename($path);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $rawToken);
        self::assertSame('sha256:'.hash('sha256', $rawToken), $this->user()->getResetToken());

        $page = $this->client->request('GET', $path);
        $this->assertStatus(200);
        $form = $page->filter('form')->first()->form();
        $form['reset_password_form[password][first]'] = 'A-new-test-password-84!';
        $form['reset_password_form[password][second]'] = 'A-new-test-password-84!';
        $this->client->submit($form);
        $this->assertRedirectPath('/fr/login');
        $this->entityManager()->clear();
        self::assertNull($this->user()->getResetToken());
        self::assertNull($this->user()->getResetTokenExpiresAt());
        self::assertGreaterThan($oldVersion, $this->user()->getSecurityVersion());
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($this->user(), 'A-new-test-password-84!'));

        $this->client->request('GET', $path);
        $this->assertRedirectPath('/fr/reset-password');
        foreach ([$oldSession, $oldCookie] as $obsoleteCredential) {
            $this->client->getCookieJar()->clear();
            $this->client->getCookieJar()->set($obsoleteCredential);
            $this->client->request('GET', '/fr/compte/securite');
            $this->assertRedirectPath('/fr/login');
        }
        $this->passwordLogin('A-new-test-password-84!');
        $this->assertRedirectPath('/2fa');
        self::assertCount(3, $this->emails, 'A reset password must still require the email verification step.');
        self::assertNull($this->rememberCookie());
    }

    public function testResetRequestsAreRateLimitedWithoutRevealingUnknownAccounts(): void
    {
        $hash = $this->user()->getPassword();
        for ($attempt = 0; $attempt < 4; ++$attempt) {
            $this->requestPasswordReset($this->email);
            $this->assertRedirectPath('/fr/reset-password/check-email');
        }
        self::assertCount(3, $this->emails);
        $this->requestPasswordReset('unknown@example.test');
        $this->assertRedirectPath('/fr/reset-password/check-email');
        self::assertCount(3, $this->emails);
        self::assertSame($hash, $this->user()->getPassword());
    }

    public function testResetDeliveryFailurePreservesGenericResponseAndCannotAuthenticate(): void
    {
        $this->deliveryFails = true;
        $this->requestPasswordReset($this->email);
        $this->assertRedirectPath('/fr/reset-password/check-email');
        self::assertCount(0, $this->emails);
        self::assertNull($this->rememberCookie());
        $this->client->request('GET', '/fr/compte/securite');
        $this->assertRedirectPath('/fr/login');
    }

    public function testResetEmailUsesConfirmedPublicOriginDespiteHostHeader(): void
    {
        $page = $this->client->request('GET', 'https://attacker.example.test/fr/reset-password');
        $this->assertStatus(200);
        $form = $page->filter('form')->first()->form();
        $form['reset_password_request_form[email]'] = $this->email;
        $this->client->submit($form);
        self::assertCount(1, $this->emails);
        $url = $this->emails[0]->getContext()['resetTokenUrl'];
        self::assertSame('https', parse_url($url, PHP_URL_SCHEME));
        self::assertSame('app.philipfreres.com', parse_url($url, PHP_URL_HOST));
        self::assertStringNotContainsString('attacker.example.test', $url);
    }

    public function testEmailConfirmationUsesCanonicalOriginAndRestoresRouterContext(): void
    {
        $router = self::getContainer()->get('router');
        $original = clone $router->getContext();
        $original->setHost('attacker.example.test');
        $original->setScheme('http');
        $original->setHttpPort(8080);
        $router->setContext($original);
        self::getContainer()->get(EmailVerifier::class)->sendEmailConfirmation($this->user());
        self::assertCount(1, $this->emails);
        $url = $this->emails[0]->getContext()['verifyUrl'];
        self::assertSame('https', parse_url($url, PHP_URL_SCHEME));
        self::assertSame('app.philipfreres.com', parse_url($url, PHP_URL_HOST));
        self::assertNotEmpty(parse_url($url, PHP_URL_QUERY));
        self::assertSame($original, $router->getContext());
        self::assertSame('attacker.example.test', $router->getContext()->getHost());
    }

    public function testAnonymousRegistrationCannotOverwriteExistingUnverifiedAccount(): void
    {
        self::assertFalse($this->user()->isVerified());
        $originalPassword = $this->user()->getPassword();
        $page = $this->client->request('GET', '/fr/register');
        $this->assertStatus(200);
        $form = $page->filter('form')->first()->form();
        $form['registration_form[prenom]'] = 'Attacker';
        $form['registration_form[nom]'] = 'Changed';
        $form['registration_form[email]'] = $this->email;
        $form['registration_form[password][first]'] = 'An-attacker-password-84!';
        $form['registration_form[password][second]'] = 'An-attacker-password-84!';
        $form['registration_form[consentementRgpd]']->tick();
        $this->client->submit($form);
        $this->entityManager()->clear();
        self::assertSame($originalPassword, $this->user()->getPassword());
        self::assertSame('Camille', $this->user()->getPrenom());
        self::assertSame('Test', $this->user()->getNom());
        self::assertCount(0, $this->emails);
    }

    private function passwordLogin(string $password = self::PASSWORD, bool $remember = true): void
    {
        $page = $this->client->request('GET', '/fr/login');
        $this->assertStatus(200);
        $form = $page->filter('form')->first()->form();
        $form['_username'] = $this->email;
        $form['_password'] = $password;
        if ($remember) {
            $form['_remember_me']->tick();
        } else {
            $form['_remember_me']->untick();
        }
        $this->client->submit($form);
    }

    private function requestPasswordReset(string $email): void
    {
        $page = $this->client->request('GET', '/fr/reset-password');
        $this->assertStatus(200);
        $form = $page->filter('form')->first()->form();
        $form['reset_password_request_form[email]'] = $email;
        $this->client->submit($form);
    }

    private function completeVerification(): void
    {
        $this->submitCode($this->latestCode());
        $this->assertStatus(302);
        self::assertNotSame('/2fa', parse_url($this->client->getResponse()->headers->get('Location'), PHP_URL_PATH));
        $this->client->request('GET', '/fr/compte/securite');
        $this->assertStatus(200);
    }

    private function submitCode(string $code): void
    {
        $page = $this->client->request('GET', '/2fa');
        $this->assertStatus(200);
        $form = $page->filter('form[action="/2fa_check"]')->form();
        $form['_auth_code'] = $code;
        $this->client->submit($form);
    }

    private function resendCode(): void
    {
        $page = $this->client->request('GET', '/2fa');
        $this->assertStatus(200);
        $this->client->submit($page->filter('form[action="/fr/2fa/resend"]')->form());
        $this->assertRedirectPath('/2fa');
    }

    private function latestCode(): string
    {
        return $this->emails[array_key_last($this->emails)]->getContext()['code'];
    }

    private function changeChallenge(array $changes): void
    {
        $session = $this->client->getSession();
        $session->set(EmailChallenge::KEY, array_replace($session->get(EmailChallenge::KEY), $changes));
        $session->save();
    }

    private function forgetBrowserSession(): void
    {
        // Symfony's test storage uses MOCKSESSID even when production has a dedicated cookie name.
        $sessionName = $this->client->getSession()->getName();
        $this->client->getCookieJar()->expire($sessionName);
        self::assertNull($this->client->getCookieJar()->get($sessionName));
    }

    private function rememberCookie(): ?Cookie
    {
        return $this->client->getCookieJar()->get(self::REMEMBER_COOKIE);
    }

    private function rememberedDeviceParts(): array
    {
        $cookie = $this->rememberCookie();
        self::assertNotNull($cookie);
        return explode(':', RememberMeDetails::fromRawCookie($cookie->getValue())->getValue(), 2);
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine')->getManager();
    }

    private function user(): Utilisateur
    {
        return $this->entityManager()->find(Utilisateur::class, $this->userId);
    }

    private function assertRedirectPath(string $path): void
    {
        $this->assertStatus(302);
        self::assertSame($path, parse_url($this->client->getResponse()->headers->get('Location'), PHP_URL_PATH));
    }

    private function assertStatus(int $status): void
    {
        $response = $this->client->getResponse();
        self::assertSame($status, $response->getStatusCode(), substr(strip_tags($response->getContent()), 0, 1600));
    }
}
