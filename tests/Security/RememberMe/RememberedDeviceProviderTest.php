<?php

declare(strict_types=1);

namespace App\Tests\Security\RememberMe;

use App\Entity\Utilisateur;
use App\Security\RememberMe\RememberedDeviceProvider;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\RememberMe\PersistentToken;
use Symfony\Component\Security\Core\Exception\TokenNotFoundException;

final class RememberedDeviceProviderTest extends TestCase
{
    private const SECRET = 'unit-test-application-secret';
    private const VALIDATOR = 'RAW_COOKIE_VALIDATOR_NEVER_STORE';
    private const SERIES = 'random-series-generated-by-symfony';

    public function testCreationPersistsOnlyHashedValidatorAndCredentialFingerprint(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchAssociative')->willReturn($this->account());
        $connection->expects(self::once())->method('insert')->with('remembered_device', self::callback(function (array $data): bool {
            self::assertSame(hash('sha256', self::VALIDATOR), $data['validator_hash']);
            self::assertSame($this->fingerprint($this->account()), $data['credential_fingerprint']);
            self::assertStringNotContainsString(self::VALIDATOR, json_encode($data, JSON_THROW_ON_ERROR));
            self::assertNull($data['previous_validator_hash']);
            self::assertNull($data['previous_valid_until']);
            self::assertEquals($data['created_at'], $data['last_used']);
            self::assertEqualsWithDelta(time(), $data['created_at']->getTimestamp(), 2);
            return true;
        }))->willReturn(1);
        $connection->expects(self::once())->method('fetchFirstColumn')->with(self::stringContains('LIMIT 100'))->willReturn([]);
        $connection->expects(self::never())->method('executeStatement');

        (new RememberedDeviceProvider($connection, self::SECRET))->createNewToken($this->token());
    }

    public function testCleanupIsBoundedAndDeletesOnlySelectedExpiredSeries(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn($this->account());
        $connection->expects(self::once())->method('insert')->willReturn(1);
        $connection->expects(self::once())->method('fetchFirstColumn')->with(self::stringContains('LIMIT 100'))->willReturn(['old-one', 'old-two']);
        $connection->expects(self::once())->method('executeStatement')->with(
            'DELETE FROM remembered_device WHERE series IN (:series)',
            ['series' => ['old-one', 'old-two']],
            self::anything(),
        )->willReturn(2);

        (new RememberedDeviceProvider($connection, self::SECRET))->createNewToken($this->token());
    }

    public function testLoadReturnsSymfonyPersistentTokenWithHashAndCorrectAccount(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAssociative')->willReturn($this->device());
        $token = (new RememberedDeviceProvider($connection, self::SECRET))->loadTokenBySeries(self::SERIES);

        self::assertInstanceOf(PersistentToken::class, $token);
        self::assertSame(Utilisateur::class, $token->getClass(false));
        self::assertSame('user@example.test', $token->getUserIdentifier());
        self::assertSame(hash('sha256', self::VALIDATOR), $token->getTokenValue());
        self::assertSame(self::SERIES, $token->getSeries());
    }

    #[DataProvider('invalidAccountChanges')]
    public function testCurrentDatabaseCredentialChangesRevokeExistingBrowser(string $field, mixed $value): void
    {
        $device = $this->device();
        $device[$field] = $value;
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn($device);
        $connection->expects(self::once())->method('delete')->with('remembered_device', ['series' => self::SERIES])->willReturn(1);

        $this->expectException(TokenNotFoundException::class);
        (new RememberedDeviceProvider($connection, self::SECRET))->loadTokenBySeries(self::SERIES);
    }

    public static function invalidAccountChanges(): iterable
    {
        yield 'password reset' => ['account_password', 'different-password-hash'];
        yield 'platform roles changed' => ['account_roles', '["ROLE_USER", "ROLE_SUPER_ADMIN"]'];
        yield 'logout all browsers' => ['account_security_version', 2];
        yield 'email changed' => ['account_email', 'new@example.test'];
        yield 'banned' => ['account_bannir', 1];
        yield 'temporarily disabled' => ['account_disabled', 1];
        yield 'deleted account' => ['account_id', null];
        yield 'different account reused email' => ['account_id', 999];
        yield 'absolute lifetime exceeded' => ['created_at', gmdate('Y-m-d H:i:s', time() - 91 * 86400)];
        yield 'idle lifetime exceeded' => ['last_used', gmdate('Y-m-d H:i:s', time() - 91 * 86400)];
    }

    public function testApplicationSecretRotationRevokesExistingBrowsers(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn($this->device());
        $connection->expects(self::once())->method('delete')->willReturn(1);
        $this->expectException(TokenNotFoundException::class);
        (new RememberedDeviceProvider($connection, 'rotated-secret'))->loadTokenBySeries(self::SERIES);
    }

    #[DataProvider('validatorCases')]
    public function testValidatorVerificationUsesHashesAndLimitsPreviousGrace(string $presented, ?string $previous, ?int $expiryOffset, bool $expected): void
    {
        $device = $this->device();
        $device['previous_validator_hash'] = $previous === null ? null : hash('sha256', $previous);
        $device['previous_valid_until'] = $expiryOffset === null ? null : gmdate('Y-m-d H:i:s', time() + $expiryOffset);
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAssociative')->willReturn($device);

        self::assertSame($expected, (new RememberedDeviceProvider($connection, self::SECRET))->verifyToken($this->token(), $presented));
    }

    public static function validatorCases(): iterable
    {
        yield 'current' => [self::VALIDATOR, null, null, true];
        yield 'forged' => ['wrong', null, null, false];
        yield 'database hash cannot be replayed as cookie' => [hash('sha256', self::VALIDATOR), null, null, false];
        yield 'previous within grace' => ['previous-raw', 'previous-raw', 30, true];
        yield 'previous expired' => ['previous-raw', 'previous-raw', -1, false];
        yield 'previous missing expiry' => ['previous-raw', 'previous-raw', null, false];
    }

    public function testVerifyRechecksServerRevocationAfterTokenWasLoaded(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAssociative')->willReturn(false);
        self::assertFalse((new RememberedDeviceProvider($connection, self::SECRET))->verifyToken($this->token(), self::VALIDATOR));
    }

    public function testRotationIsAtomicStoresOnlyNewHashAndPreservesAbsoluteCreation(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn($this->device());
        $connection->expects(self::once())->method('executeStatement')->with(
            self::callback(static function (string $sql): bool {
                self::assertStringContainsString('SET previous_validator_hash = :previous_hash,', $sql);
                self::assertStringContainsString('validator_hash = :validator_hash', $sql);
                self::assertStringContainsString('validator_hash = :expected_hash', $sql);
                self::assertStringNotContainsString('created_at =', $sql);
                return true;
            }),
            self::callback(static function (array $data): bool {
                self::assertSame(hash('sha256', 'new-raw-validator'), $data['validator_hash']);
                self::assertSame(hash('sha256', self::VALIDATOR), $data['previous_hash']);
                self::assertSame('[]', $data['siblings']);
                self::assertStringNotContainsString('new-raw-validator', json_encode($data, JSON_THROW_ON_ERROR));
                self::assertEqualsWithDelta(time() + 60, $data['previous_until']->getTimestamp(), 2);
                return true;
            }),
            self::anything(),
        )->willReturn(1);

        (new RememberedDeviceProvider($connection, self::SECRET))->updateToken(self::SERIES, 'new-raw-validator', new \DateTimeImmutable());
    }

    public function testCompareAndSwapRetriesAreBounded(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn($this->device());
        $connection->expects(self::exactly(8))->method('executeStatement')->willReturn(0);
        $this->expectException(TokenNotFoundException::class);
        (new RememberedDeviceProvider($connection, self::SECRET))->updateToken(self::SERIES, 'new-value', new \DateTimeImmutable());
    }

    public function testLogoutDeletesTheServerRecord(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('delete')->with('remembered_device', ['series' => self::SERIES])->willReturn(1);
        (new RememberedDeviceProvider($connection, self::SECRET))->deleteTokenBySeries(self::SERIES);
    }

    public function testDisabledAccountCannotCreateRememberedBrowser(): void
    {
        $connection = $this->createMock(Connection::class);
        $account = $this->account();
        $account['bannir'] = true;
        $connection->method('fetchAssociative')->willReturn($account);
        $connection->expects(self::never())->method('insert');
        $this->expectException(TokenNotFoundException::class);
        (new RememberedDeviceProvider($connection, self::SECRET))->createNewToken($this->token());
    }

    public function testRoleOrderAndDuplicatesDoNotRevokeEquivalentPermissions(): void
    {
        $account = $this->account();
        $account['roles'] = '["ROLE_USER", "ROLE_SUPER_ADMIN"]';
        $device = $this->device();
        $device['credential_fingerprint'] = $this->fingerprint($account);
        $device['account_roles'] = '["ROLE_SUPER_ADMIN", "ROLE_USER", "ROLE_USER"]';
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAssociative')->willReturn($device);
        self::assertSame('user@example.test', (new RememberedDeviceProvider($connection, self::SECRET))->loadTokenBySeries(self::SERIES)->getUserIdentifier());
    }

    public function testStaleParallelLoadsPreserveBothCookiesAfterResponsesArriveInReverseOrder(): void
    {
        $device = $this->device();
        $provider = new RememberedDeviceProvider($this->memoryConnection($device), self::SECRET);
        $firstSnapshot = $provider->loadTokenBySeries(self::SERIES);
        $secondSnapshot = $provider->loadTokenBySeries(self::SERIES);
        self::assertEquals($firstSnapshot->getLastUsed(), $secondSnapshot->getLastUsed());

        self::assertTrue($provider->verifyToken($firstSnapshot, self::VALIDATOR));
        $provider->updateExistingToken($this->token(), 'cookie-A', new \DateTimeImmutable());
        $provider->updateToken(self::SERIES, 'cookie-A', new \DateTimeImmutable());
        $generationStart = $device['last_used'];
        $graceDeadline = $device['previous_valid_until'];
        self::assertTrue($provider->verifyToken($secondSnapshot, self::VALIDATOR));
        $provider->updateExistingToken($this->token(), 'cookie-B', new \DateTimeImmutable());
        $provider->updateToken(self::SERIES, 'cookie-B', new \DateTimeImmutable());

        self::assertSame($generationStart, $device['last_used'], 'Sibling requests must not slide the generation start.');
        self::assertSame($graceDeadline, $device['previous_valid_until'], 'Sibling requests must not extend the old generation grace.');
        self::assertSame([hash('sha256', 'cookie-A')], json_decode($device['sibling_validator_hashes'], true));
        $device['previous_valid_until'] = gmdate('Y-m-d H:i:s', time() - 1);
        self::assertTrue($provider->verifyToken($this->token(), 'cookie-A'), 'A late response may overwrite cookie B with cookie A.');
        self::assertTrue($provider->verifyToken($this->token(), 'cookie-B'));
        self::assertFalse($provider->verifyToken($this->token(), self::VALIDATOR));
    }

    public function testNextGenerationMovesEveryPreviousSiblingIntoFixedGrace(): void
    {
        $device = $this->device();
        $device['validator_hash'] = hash('sha256', 'cookie-B');
        $device['sibling_validator_hashes'] = json_encode([hash('sha256', 'cookie-A')]);
        $provider = new RememberedDeviceProvider($this->memoryConnection($device), self::SECRET);
        $oldSibling = new PersistentToken(Utilisateur::class, 'user@example.test', self::SERIES, 'cookie-A', new \DateTimeImmutable('-2 minutes'), false);
        self::assertTrue($provider->verifyToken($oldSibling, 'cookie-A'));
        $provider->updateExistingToken($oldSibling, 'cookie-C', new \DateTimeImmutable());
        $provider->updateToken(self::SERIES, 'cookie-C', new \DateTimeImmutable());

        self::assertSame('[]', $device['sibling_validator_hashes']);
        self::assertSame(hash('sha256', 'cookie-B'), $device['previous_validator_hash']);
        self::assertSame([hash('sha256', 'cookie-A')], json_decode($device['previous_sibling_validator_hashes'], true));
        self::assertTrue($provider->verifyToken($this->token(), 'cookie-A'));
        self::assertTrue($provider->verifyToken($this->token(), 'cookie-B'));
        $device['previous_valid_until'] = gmdate('Y-m-d H:i:s', time() - 1);
        self::assertFalse($provider->verifyToken($this->token(), 'cookie-A'));
        self::assertFalse($provider->verifyToken($this->token(), 'cookie-B'));
        self::assertTrue($provider->verifyToken($this->token(), 'cookie-C'));
    }

    public function testCompareAndSwapRetryMergesConcurrentSiblingInsteadOfLosingIt(): void
    {
        $device = $this->device();
        $updates = 0;
        $connection = $this->memoryConnection($device, function (array|false &$row) use (&$updates): void {
            if (++$updates === 1) {
                // Another request completes rotation A after our SELECT but before UPDATE.
                $row['previous_validator_hash'] = $row['validator_hash'];
                $row['validator_hash'] = hash('sha256', 'concurrent-A');
                $row['last_used'] = gmdate('Y-m-d H:i:s');
                $row['previous_valid_until'] = gmdate('Y-m-d H:i:s', time() + 60);
            }
        });
        $provider = new RememberedDeviceProvider($connection, self::SECRET);
        self::assertTrue($provider->verifyToken($this->token(), self::VALIDATOR));
        $provider->updateExistingToken($this->token(), 'cookie-B', new \DateTimeImmutable());
        $provider->updateToken(self::SERIES, 'cookie-B', new \DateTimeImmutable());

        self::assertSame(2, $updates);
        self::assertTrue($provider->verifyToken($this->token(), 'concurrent-A'));
        self::assertTrue($provider->verifyToken($this->token(), 'cookie-B'));
    }

    public function testCompareAndSwapRetryCannotResurrectConcurrentLogout(): void
    {
        $device = $this->device();
        $updates = 0;
        $connection = $this->memoryConnection($device, function (array|false &$row) use (&$updates): void {
            ++$updates;
            $row = false;
        });
        $provider = new RememberedDeviceProvider($connection, self::SECRET);
        try {
            $provider->updateToken(self::SERIES, 'cookie-B', new \DateTimeImmutable());
            self::fail('A revoked series cannot be recreated by a rotation.');
        } catch (TokenNotFoundException) {
            self::assertFalse($device);
            self::assertSame(1, $updates);
        }
    }

    public function testConcurrentGenerationIsBoundedAndFailureDoesNotEraseOtherCookies(): void
    {
        $device = $this->device();
        $device['last_used'] = gmdate('Y-m-d H:i:s');
        $device['sibling_validator_hashes'] = json_encode(array_map(static fn (int $i): string => hash('sha256', 'sibling-' . $i), range(1, 31)));
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn($device);
        $connection->expects(self::never())->method('executeStatement');
        $connection->expects(self::never())->method('delete');
        $this->expectException(TokenNotFoundException::class);
        (new RememberedDeviceProvider($connection, self::SECRET))->updateToken(self::SERIES, 'overflow', new \DateTimeImmutable());
    }

    public function testExpiredPresentedCredentialCannotRotateAfterDelayedAuthentication(): void
    {
        $device = $this->device();
        $device['validator_hash'] = hash('sha256', 'new-generation');
        $device['previous_validator_hash'] = hash('sha256', self::VALIDATOR);
        $device['previous_valid_until'] = gmdate('Y-m-d H:i:s', time() + 30);
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturnCallback(static function () use (&$device): array { return $device; });
        $connection->expects(self::never())->method('executeStatement');
        $provider = new RememberedDeviceProvider($connection, self::SECRET);
        self::assertTrue($provider->verifyToken($this->token(), self::VALIDATOR));
        $device['previous_valid_until'] = gmdate('Y-m-d H:i:s', time() - 1);
        $provider->updateExistingToken($this->token(), 'delayed-cookie', new \DateTimeImmutable());
        $this->expectException(TokenNotFoundException::class);
        $provider->updateToken(self::SERIES, 'delayed-cookie', new \DateTimeImmutable());
    }

    public function testSymfonyStoredHashHookUsesActualVerifiedValidatorWithoutDoubleHashing(): void
    {
        $device = $this->device();
        $provider = new RememberedDeviceProvider($this->memoryConnection($device), self::SECRET);
        $storedToken = $provider->loadTokenBySeries(self::SERIES);
        self::assertSame(hash('sha256', self::VALIDATOR), $storedToken->getTokenValue());
        self::assertTrue($provider->verifyToken($storedToken, self::VALIDATOR));
        $provider->updateExistingToken($storedToken, 'next-cookie', new \DateTimeImmutable());
        $provider->updateToken(self::SERIES, 'next-cookie', new \DateTimeImmutable());
        self::assertTrue($provider->verifyToken($storedToken, 'next-cookie'));
        self::assertSame(hash('sha256', self::VALIDATOR), $device['previous_validator_hash']);
    }

    public function testRequestResetDiscardsVerifiedCredentialBeforeRotationHook(): void
    {
        $device = $this->device();
        $provider = new RememberedDeviceProvider($this->memoryConnection($device), self::SECRET);
        self::assertTrue($provider->verifyToken($this->token(), self::VALIDATOR));
        $provider->reset();
        $this->expectException(TokenNotFoundException::class);
        $provider->updateExistingToken($this->token(), 'next-cookie', new \DateTimeImmutable());
    }

    private function memoryConnection(array|false &$device, ?\Closure $beforeUpdate = null): Connection
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAssociative')->willReturnCallback(static function () use (&$device): array|false { return $device; });
        $connection->method('executeStatement')->willReturnCallback(static function (string $sql, array $parameters) use (&$device, $beforeUpdate): int {
            if ($beforeUpdate !== null) {
                $beforeUpdate($device);
            }
            if ($device === false || $device['validator_hash'] !== $parameters['expected_hash']) {
                return 0;
            }
            $device['validator_hash'] = $parameters['validator_hash'];
            $device['sibling_validator_hashes'] = $parameters['siblings'];
            $device['previous_validator_hash'] = $parameters['previous_hash'];
            $device['previous_sibling_validator_hashes'] = $parameters['previous_siblings'];
            $device['previous_valid_until'] = $parameters['previous_until']?->format('Y-m-d H:i:s');
            $device['last_used'] = $parameters['last_used']->format('Y-m-d H:i:s');
            return 1;
        });
        return $connection;
    }

    private function token(): PersistentToken
    {
        return new PersistentToken(Utilisateur::class, 'user@example.test', self::SERIES, self::VALIDATOR, new \DateTimeImmutable(), false);
    }

    private function account(): array
    {
        return ['id' => 12, 'email' => 'user@example.test', 'password' => 'hashed-password', 'roles' => '["ROLE_USER"]', 'security_version' => 1, 'bannir' => null, 'desactiver_temporairement' => false];
    }

    private function device(): array
    {
        $account = $this->account();
        return [
            'series' => self::SERIES,
            'username' => $account['email'],
            'validator_hash' => hash('sha256', self::VALIDATOR),
            'sibling_validator_hashes' => '[]',
            'previous_validator_hash' => null,
            'previous_sibling_validator_hashes' => '[]',
            'previous_valid_until' => null,
            'credential_fingerprint' => $this->fingerprint($account),
            'created_at' => gmdate('Y-m-d H:i:s', time() - 7 * 86400),
            'last_used' => gmdate('Y-m-d H:i:s', time() - 120),
            'account_id' => $account['id'],
            'account_email' => $account['email'],
            'account_password' => $account['password'],
            'account_roles' => $account['roles'],
            'account_security_version' => $account['security_version'],
            'account_bannir' => $account['bannir'],
            'account_disabled' => $account['desactiver_temporairement'],
        ];
    }

    private function fingerprint(array $account): string
    {
        $roles = array_values(array_unique(json_decode($account['roles'], true, flags: JSON_THROW_ON_ERROR)));
        sort($roles, SORT_STRING);
        return hash_hmac('sha256', json_encode([(string) $account['id'], $account['email'], $account['password'], $roles, (int) $account['security_version'], (bool) $account['bannir'], (bool) $account['desactiver_temporairement']], JSON_THROW_ON_ERROR), self::SECRET);
    }
}
