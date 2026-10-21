<?php

declare(strict_types=1);
namespace App\Service\Intescia;

use App\Entity\Entite;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\{Address, Email};
use Twig\Environment;

final class InsightDigest
{
    public function __construct(
        private readonly InsightStore $store, private readonly InsightRecipients $recipients,
        private readonly TransportInterface $transport, private readonly Environment $twig,
        private readonly EntityManagerInterface $entities,
        #[Autowire('%env(default:app.auth_mail_from_default:AUTH_MAIL_FROM)%')] private readonly string $sender,
        #[Autowire('%env(default::APP_URL)%')] private readonly ?string $appUrl,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {}

    /** Latest scheduled occurrence, in Paris calendar days (including daylight saving changes). */
    public static function due(array $config, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        if (empty($config['digest_enabled']) || empty($config['digest_start'])) { return null; }
        $zone = new \DateTimeZone('Europe/Paris');
        $local = $now->setTimezone($zone);
        $start = new \DateTimeImmutable($config['digest_start'].' '.$config['digest_time'], $zone);
        if ($local < $start) { return null; }
        $days = (int) $start->setTime(0, 0)->diff($local->setTime(0, 0))->format('%a');
        $interval = (int) $config['digest_interval'];
        if (!in_array($interval, [1, 2, 7], true)) { return null; }
        $slot = $start->modify('+'.(intdiv($days, $interval) * $interval).' days');
        if ($slot > $local) { $slot = $slot->modify('-'.$interval.' days'); }
        // No catch-up burst for missed days; only the scheduled day may be sent.
        return $slot >= $start && $slot->format('Y-m-d') === $local->format('Y-m-d') ? $slot : null;
    }

    /** Caller holds the tenant lock. Reserve each delivery before SMTP; never blindly retry. */
    public function run(int $tenant, bool $dryRun = false, ?\DateTimeImmutable $now = null): array
    {
        $now = ($now ?? new \DateTimeImmutable())->setTimezone(new \DateTimeZone('UTC'));
        $config = $this->store->config($tenant);
        $result = ['eligible' => 0, 'sent' => 0, 'uncertain' => 0];
        $slot = self::due($config, $now);
        if (!$slot || empty($config['authorized']) || empty($config['sync_ok']) || !$config['last_success'] || strtotime($config['last_success'].' UTC') < $now->getTimestamp() - 86400) { return $result; }
        $markets = $this->store->db->fetchAllAssociative('SELECT title, deadline FROM insight_market WHERE tenant_id = ? AND active = 1 AND deadline > ? ORDER BY deadline ASC, title ASC', [$tenant, $now->format('Y-m-d H:i:s')]);
        if (!$markets) { return $result; }
        foreach ($markets as &$market) {
            $deadline = new \DateTimeImmutable($market['deadline'], new \DateTimeZone('UTC'));
            $market['days'] = (int) ceil(($deadline->getTimestamp() - $now->getTimestamp()) / 86400);
            $market['deadline'] = $deadline;
        }
        unset($market);
        $users = $this->recipients->available($tenant);
        foreach (array_unique(array_map('intval', $config['recipients'])) as $userId) {
            if (!isset($users[$userId])) { continue; }
            // One digest per local day/person, even if the schedule is edited that day.
            $id = hash('sha256', 'digest:'.$tenant.':'.$userId.':'.$slot->format('Y-m-d'));
            if ($this->store->db->fetchOne('SELECT id FROM insight_digest_delivery WHERE id = ?', [$id])) { continue; }
            ++$result['eligible'];
            if ($dryRun) { continue; }
            $email = $this->createEmail($tenant, $markets, $now, $users[$userId]['email']);
            $this->store->db->insert('insight_digest_delivery', ['id' => $id, 'tenant_id' => $tenant, 'user_id' => $userId, 'scheduled_date' => $slot->format('Y-m-d'), 'status' => 'sending', 'created_at' => InsightStore::now(), 'updated_at' => InsightStore::now()]);
            try {
                $this->transport->send($email);
                $this->store->db->update('insight_digest_delivery', ['status' => 'sent', 'updated_at' => InsightStore::now()], ['id' => $id]);
                ++$result['sent'];
            } catch (\Throwable) {
                $this->store->db->update('insight_digest_delivery', ['status' => 'uncertain', 'updated_at' => InsightStore::now()], ['id' => $id]);
                ++$result['uncertain'];
            }
        }
        return $result;
    }
    public function createEmail(int $tenant, array $markets, \DateTimeImmutable $now, string $address): Email
    {
        $company = $this->entities->find(Entite::class, $tenant);
        if (!$company || !filter_var($this->appUrl, FILTER_VALIDATE_URL)) { throw new InsightException('Société ou APP_URL indisponible pour le récapitulatif.'); }
        $color = static fn (?string $value, string $fallback): string => preg_match('/^#[0-9a-f]{6}$/iD', $value ?? '') ? $value : $fallback;
        $context = ['company' => $company->getNom(), 'primary' => $color($company->getCouleurPrincipal(), '#183f33'), 'accent' => $color($company->getCouleurSecondaire(), '#d2c08a'), 'markets' => $markets, 'date' => $now, 'url' => rtrim($this->appUrl, '/').'/fr/administrateur/'.$tenant.'/marches', 'logo' => null];
        $logoRoot = realpath($this->projectDir.'/public/uploads/photos/entite/logo');
        $logo = $company->getLogo() && $logoRoot ? realpath($logoRoot.'/'.$company->getLogo()) : false;
        if (!$logo || !str_starts_with($logo, $logoRoot.DIRECTORY_SEPARATOR) || !is_file($logo)) { $logo = null; }
            $email = (new Email())->from(new Address($this->sender, $company->getNom().' · Marchés'))->to($address)
                ->subject('Vos marchés à venir · '.count($markets).' échéance'.(count($markets) > 1 ? 's' : ''));
            if ($logo) { $email->embedFromPath($logo, 'company-logo'); $context['logo'] = 'cid:company-logo'; }
            $email->html($this->twig->render('emails/marches/digest.html.twig', $context))->text($this->twig->render('emails/marches/digest.txt.twig', $context));
        return $email;
    }

}
