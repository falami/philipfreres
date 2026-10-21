<?php

declare(strict_types=1);
namespace App\Service\Intescia;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class InsightVault
{
    public function __construct(#[Autowire(env: 'default::INTESCIA_ENCRYPTION_KEY')] private readonly ?string $key) {}

    public function ready(): bool
    {
        return function_exists('sodium_crypto_secretbox') && strlen(base64_decode($this->key ?? '', true) ?: '') === 32;
    }

    private function key(): string
    {
        if (!$this->ready()) {
            throw new InsightException('La clé INTESCIA_ENCRYPTION_KEY (32 octets en base64) et l’extension sodium sont nécessaires.');
        }
        return base64_decode($this->key, true);
    }

    public function seal(array $value): string
    {
        $key = $this->key();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce.sodium_crypto_secretbox(json_encode($value, JSON_THROW_ON_ERROR), $nonce, $key));
    }

    public function open(?string $value): array
    {
        if (!$value) { return []; }
        $key = $this->key();
        $bytes = base64_decode($value, true);
        if ($bytes === false || strlen($bytes) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new InsightException('Configuration chiffrée illisible.');
        }
        $plain = sodium_crypto_secretbox_open(substr($bytes, 24), substr($bytes, 0, 24), $key);
        if ($plain === false) { throw new InsightException('Impossible de déchiffrer la connexion Intescia. Vérifiez la clé du serveur.'); }
        return json_decode($plain, true, 512, JSON_THROW_ON_ERROR);
    }
}
