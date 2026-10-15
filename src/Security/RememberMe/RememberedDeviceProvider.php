<?php

declare(strict_types=1);

namespace App\Security\RememberMe;

use App\Entity\Utilisateur;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Authentication\RememberMe\PersistentToken;
use Symfony\Component\Security\Core\Authentication\RememberMe\PersistentTokenInterface;
use Symfony\Component\Security\Core\Authentication\RememberMe\TokenProviderInterface;
use Symfony\Component\Security\Core\Authentication\RememberMe\TokenVerifierInterface;
use Symfony\Component\Security\Core\Exception\TokenNotFoundException;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Used by Symfony's standard PersistentRememberMeHandler after full authentication.
 * No raw cookie validator is ever written to the database.
 */
final class RememberedDeviceProvider implements TokenProviderInterface, TokenVerifierInterface, ResetInterface
{
    public const MAX_LIFETIME_SECONDS = 90 * 24 * 60 * 60;
    public const PREVIOUS_TOKEN_GRACE_SECONDS = 60;
    public const MAX_GENERATION_VALIDATORS = 32;
    private const MAX_ROTATION_ATTEMPTS = 8;

    /** @var array<string, array{presented_hash: string, issued_hash: string, username: string}> */
    private array $pendingRotations = [];

    /** @var array<string, array{hash: string, username: string, verified_at: int}> */
    private array $verifiedCredentials = [];

    public function __construct(
        private readonly Connection $connection,
        #[Autowire('%kernel.secret%')] private readonly string $secret,
    ) {}

    public function loadTokenBySeries(string $series): PersistentTokenInterface
    {
        $device = $this->loadActiveDevice($series);

        return new PersistentToken(
            Utilisateur::class,
            $device['username'],
            $series,
            $device['validator_hash'],
            $this->date($device['last_used']),
            false,
        );
    }

    public function deleteTokenBySeries(string $series): void
    {
        unset($this->pendingRotations[$series], $this->verifiedCredentials[$series]);
        $this->connection->delete('remembered_device', ['series' => $series]);
    }

    public function createNewToken(#[\SensitiveParameter] PersistentTokenInterface $token): void
    {
        // Read credentials directly: Doctrine's identity map may still hold the
        // account as it was before a password reset, suspension or revocation.
        $account = $this->connection->fetchAssociative(
            'SELECT id, email, password, roles, security_version, bannir, desactiver_temporairement FROM utilisateur WHERE email = :email',
            ['email' => $token->getUserIdentifier()],
        );
        if (!$account || !$this->accountIsActive($account)) {
            throw new TokenNotFoundException('This account cannot be remembered.');
        }

        $now = $this->now();
        $this->connection->insert('remembered_device', [
            'series' => $token->getSeries(),
            'username' => $account['email'],
            'validator_hash' => $this->hashValidator($token->getTokenValue()),
            'sibling_validator_hashes' => '[]',
            'previous_validator_hash' => null,
            'previous_sibling_validator_hashes' => '[]',
            'previous_valid_until' => null,
            'credential_fingerprint' => $this->fingerprint($account),
            'created_at' => $now,
            'last_used' => $now,
        ], [
            'previous_valid_until' => Types::DATETIME_IMMUTABLE,
            'created_at' => Types::DATETIME_IMMUTABLE,
            'last_used' => Types::DATETIME_IMMUTABLE,
        ]);

        // Bound cleanup work per successful new browser; never scan/delete the
        // complete table in a login request.
        $cutoff = $now->modify('-' . self::MAX_LIFETIME_SECONDS . ' seconds');
        $expiredSeries = $this->connection->fetchFirstColumn(
            'SELECT series FROM remembered_device WHERE created_at <= :cutoff OR last_used <= :cutoff ORDER BY created_at ASC LIMIT 100',
            ['cutoff' => $cutoff],
            ['cutoff' => Types::DATETIME_IMMUTABLE],
        );
        if ($expiredSeries !== []) {
            $this->connection->executeStatement(
                'DELETE FROM remembered_device WHERE series IN (:series)',
                ['series' => $expiredSeries],
                ['series' => ArrayParameterType::STRING],
            );
        }
    }

    public function verifyToken(#[\SensitiveParameter] PersistentTokenInterface $token, #[\SensitiveParameter] string $tokenValue): bool
    {
        unset($this->verifiedCredentials[$token->getSeries()]);
        try {
            // Recheck persistence so logout or account revocation after the first
            // load cannot be bypassed using the previously loaded token object.
            $device = $this->loadActiveDevice($token->getSeries());
        } catch (TokenNotFoundException) {
            return false;
        }

        if ($device['username'] !== $token->getUserIdentifier()) {
            return false;
        }
        $hash = $this->hashValidator($tokenValue);
        $now = $this->now();
        if (!$this->acceptsHash($device, $hash, $now)) {
            return false;
        }
        $this->verifiedCredentials[$token->getSeries()] = [
            'hash' => $hash,
            'username' => $token->getUserIdentifier(),
            'verified_at' => $now->getTimestamp(),
        ];
        return true;
    }

    public function updateExistingToken(#[\SensitiveParameter] PersistentTokenInterface $token, #[\SensitiveParameter] string $tokenValue, \DateTimeInterface $lastUsed): void
    {
        // Current Symfony versions pass the provider's stored HASH into this
        // hook, older versions passed the raw cookie. Use the actual credential
        // verified above, independently of that internal representation.
        $verified = $this->verifiedCredentials[$token->getSeries()] ?? null;
        unset($this->verifiedCredentials[$token->getSeries()]);
        if ($verified === null || $verified['username'] !== $token->getUserIdentifier()
            || $verified['verified_at'] + self::PREVIOUS_TOKEN_GRACE_SECONDS < $this->now()->getTimestamp()) {
            throw new TokenNotFoundException('The remembered credential was not verified.');
        }
        $this->pendingRotations[$token->getSeries()] = [
            'presented_hash' => $verified['hash'],
            'issued_hash' => $this->hashValidator($tokenValue),
            'username' => $token->getUserIdentifier(),
        ];
    }

    public function reset(): void
    {
        $this->pendingRotations = [];
        $this->verifiedCredentials = [];
    }

    public function updateToken(string $series, #[\SensitiveParameter] string $tokenValue, \DateTimeInterface $lastUsed): void
    {
        $issuedHash = $this->hashValidator($tokenValue);
        $pending = $this->pendingRotations[$series] ?? null;
        unset($this->pendingRotations[$series]);

        for ($attempt = 0; $attempt < self::MAX_ROTATION_ATTEMPTS; ++$attempt) {
            $device = $this->loadActiveDevice($series);
            $now = $this->now();
            if ($pending !== null && ($pending['issued_hash'] !== $issuedHash
                || $pending['username'] !== $device['username']
                || !$this->acceptsHash($device, $pending['presented_hash'], $now))) {
                throw new TokenNotFoundException('The remembered credential is no longer valid.');
            }

            $generationStart = $this->date($device['last_used']);
            if ($generationStart->getTimestamp() + self::PREVIOUS_TOKEN_GRACE_SECONDS < $now->getTimestamp()) {
                // Start a new generation. The previous generation's complete
                // family gets a fixed 60-second grace, never extended by siblings.
                $previousHash = $device['validator_hash'];
                $previousSiblings = $device['sibling_validator_hashes'];
                $previousUntil = $now->modify('+' . self::PREVIOUS_TOKEN_GRACE_SECONDS . ' seconds');
                $siblings = [];
                $generationStart = $now;
            } else {
                // Two requests may both have loaded the old generation before
                // either rotates. Preserve both resulting cookies, independently
                // of the order in which their HTTP responses reach the browser.
                $siblings = array_values(array_unique([...$device['sibling_validator_hashes'], $device['validator_hash']]));
                if (count($siblings) >= self::MAX_GENERATION_VALIDATORS) {
                    throw new TokenNotFoundException('Too many concurrent remembered-browser rotations.');
                }
                $previousHash = $device['previous_validator_hash'];
                $previousSiblings = $device['previous_sibling_validator_hashes'];
                $previousUntil = $device['previous_valid_until'] === null ? null : $this->date($device['previous_valid_until']);
            }

            $updated = $this->connection->executeStatement(
                'UPDATE remembered_device SET previous_validator_hash = :previous_hash, previous_sibling_validator_hashes = :previous_siblings, previous_valid_until = :previous_until, validator_hash = :validator_hash, sibling_validator_hashes = :siblings, last_used = :last_used WHERE series = :series AND validator_hash = :expected_hash AND credential_fingerprint = :fingerprint AND created_at > :cutoff',
                [
                    'previous_hash' => $previousHash,
                    'previous_siblings' => json_encode($previousSiblings, JSON_THROW_ON_ERROR),
                    'previous_until' => $previousUntil,
                    'validator_hash' => $issuedHash,
                    'siblings' => json_encode($siblings, JSON_THROW_ON_ERROR),
                    'last_used' => $generationStart,
                    'series' => $series,
                    'expected_hash' => $device['validator_hash'],
                    'fingerprint' => $device['credential_fingerprint'],
                    'cutoff' => $now->modify('-' . self::MAX_LIFETIME_SECONDS . ' seconds'),
                ],
                [
                    'previous_until' => Types::DATETIME_IMMUTABLE,
                    'last_used' => Types::DATETIME_IMMUTABLE,
                    'cutoff' => Types::DATETIME_IMMUTABLE,
                ],
            );
            if ($updated === 1) {
                return;
            }
            // Reload after a concurrent update. A deletion/account revocation
            // fails loadActiveDevice(), so retries cannot resurrect a browser.
        }

        throw new TokenNotFoundException('Concurrent remembered-browser rotation could not complete.');
    }

    private function loadActiveDevice(string $series): array
    {
        if ($series === '' || strlen($series) > 88) {
            throw new TokenNotFoundException('Invalid remembered browser.');
        }
        $device = $this->connection->fetchAssociative(
            'SELECT d.*, u.id AS account_id, u.email AS account_email, u.password AS account_password, u.roles AS account_roles, u.security_version AS account_security_version, u.bannir AS account_bannir, u.desactiver_temporairement AS account_disabled FROM remembered_device d LEFT JOIN utilisateur u ON u.email = d.username WHERE d.series = :series',
            ['series' => $series],
        );
        if (!$device) {
            throw new TokenNotFoundException('This remembered browser was revoked.');
        }
        if (!hash_equals($device['series'], $series)) {
            throw new TokenNotFoundException('Invalid remembered-browser series.');
        }
        $account = [
            'id' => $device['account_id'],
            'email' => $device['account_email'],
            'password' => $device['account_password'],
            'roles' => $device['account_roles'],
            'security_version' => $device['account_security_version'],
            'bannir' => $device['account_bannir'],
            'desactiver_temporairement' => $device['account_disabled'],
        ];
        $cutoff = $this->now()->modify('-' . self::MAX_LIFETIME_SECONDS . ' seconds');
        if (!$this->accountIsActive($account)
            || $this->date($device['created_at']) <= $cutoff
            || $this->date($device['last_used']) <= $cutoff
            || !hash_equals($device['credential_fingerprint'], $this->fingerprint($account))) {
            $this->deleteTokenBySeries($series);
            throw new TokenNotFoundException('This remembered browser has expired or was revoked.');
        }

        $device['sibling_validator_hashes'] = $this->decodeFamily($device['sibling_validator_hashes']);
        $device['previous_sibling_validator_hashes'] = $this->decodeFamily($device['previous_sibling_validator_hashes']);
        return $device;
    }

    private function acceptsHash(array $device, string $hash, \DateTimeImmutable $now): bool
    {
        if ($this->matchesFamily($hash, [$device['validator_hash'], ...$device['sibling_validator_hashes']])) {
            return true;
        }

        return is_string($device['previous_validator_hash'])
            && $device['previous_valid_until'] !== null
            && $this->date($device['previous_valid_until']) > $now
            && $this->matchesFamily($hash, [$device['previous_validator_hash'], ...$device['previous_sibling_validator_hashes']]);
    }

    /** @param list<string> $family */
    private function matchesFamily(string $hash, array $family): bool
    {
        $matches = false;
        foreach ($family as $candidate) {
            $matches = hash_equals($candidate, $hash) || $matches;
        }
        return $matches;
    }

    /** @return list<string> */
    private function decodeFamily(string $json): array
    {
        try {
            $family = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new TokenNotFoundException('Invalid remembered-browser state.');
        }
        if (!is_array($family) || !array_is_list($family) || count($family) >= self::MAX_GENERATION_VALIDATORS) {
            throw new TokenNotFoundException('Invalid remembered-browser state.');
        }
        foreach ($family as $hash) {
            if (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)) {
                throw new TokenNotFoundException('Invalid remembered-browser state.');
            }
        }
        return $family;
    }

    private function accountIsActive(array $account): bool
    {
        return $account['id'] !== null && is_string($account['password']) && $account['password'] !== ''
            && !(bool) $account['bannir'] && !(bool) $account['desactiver_temporairement'];
    }

    private function fingerprint(array $account): string
    {
        return hash_hmac('sha256', json_encode([
            (string) $account['id'],
            (string) $account['email'],
            (string) $account['password'],
            $this->normalizedRoles($account['roles']),
            (int) $account['security_version'],
            (bool) $account['bannir'],
            (bool) $account['desactiver_temporairement'],
        ], JSON_THROW_ON_ERROR), $this->secret);
    }

    /** @return list<string> */
    private function normalizedRoles(string $json): array
    {
        try {
            $roles = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new TokenNotFoundException('Invalid account roles.');
        }
        if (!is_array($roles) || !array_is_list($roles)) {
            throw new TokenNotFoundException('Invalid account roles.');
        }
        foreach ($roles as $role) {
            if (!is_string($role)) {
                throw new TokenNotFoundException('Invalid account roles.');
            }
        }
        $roles = array_values(array_unique($roles));
        sort($roles, SORT_STRING);
        return $roles;
    }

    private function hashValidator(#[\SensitiveParameter] string $validator): string
    {
        return hash('sha256', $validator);
    }

    private function date(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date, new \DateTimeZone('UTC'));
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
