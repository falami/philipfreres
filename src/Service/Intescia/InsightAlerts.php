<?php

declare(strict_types=1);
namespace App\Service\Intescia;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

final class InsightAlerts
{
    public function __construct(
        private readonly InsightStore $store, private readonly InsightRecipients $recipients,
        private readonly TransportInterface $transport, private readonly Environment $twig,
        private readonly UrlGeneratorInterface $router,
        #[Autowire('%env(default:app.auth_mail_from_default:AUTH_MAIL_FROM)%')] private readonly string $sender,
        #[Autowire('%env(default::APP_URL)%')] private readonly ?string $appUrl,
    ) {}

    /** Called under the tenant lock; a sending/uncertain delivery is never automatically retried. */
    public function run(int $tenant, bool $dryRun = false, ?\DateTimeImmutable $now = null): array
    {
        $config = $this->store->config($tenant);
        $now = ($now ?? new \DateTimeImmutable())->setTimezone(new \DateTimeZone('UTC'));
        $result = ['eligible' => 0, 'sent' => 0, 'uncertain' => 0];
        if (!$config['alerts'] || empty($config['sync_ok']) || !$config['last_success'] || strtotime($config['last_success'].' UTC') < $now->getTimestamp() - 86400) { return $result; }
        if (!filter_var($this->appUrl, FILTER_VALIDATE_URL)) { throw new InsightException('APP_URL doit être configuré avant les envois.'); }
        $users = $this->recipients->available($tenant);
        $limit = $now->modify('+'.(int) $config['lead_hours'].' hours');
        $markets = $this->store->db->fetchAllAssociative('SELECT id, deadline FROM insight_market WHERE tenant_id = ? AND active = 1 AND deadline > ? AND deadline <= ? ORDER BY deadline ASC', [$tenant, $now->format('Y-m-d H:i:s'), $limit->format('Y-m-d H:i:s')]);
        foreach ($markets as $marketRef) {
            $market = $this->store->market($tenant, $marketRef['id']);
            $cache = $this->store->cache($tenant, $market['payload']['contract_id']);
            foreach (array_unique(array_map('intval', $config['recipients'])) as $userId) {
                if (!isset($users[$userId])) { continue; }
                $id = hash('sha256', $tenant.':'.$market['id'].':'.$userId.':'.$market['deadline']);
                if ($this->store->db->fetchOne('SELECT id FROM insight_delivery WHERE id = ?', [$id])) { continue; }
                ++$result['eligible'];
                if ($dryRun) { continue; }
                $url = rtrim($this->appUrl ?? '', '/').$this->router->generate('app_administrateur_marches_detail', ['entite' => $tenant, 'id' => $market['id'], '_locale' => 'fr']);
                $deadline = new \DateTimeImmutable($market['deadline'], new \DateTimeZone('UTC'));
                $context = ['market' => $market, 'contract' => $cache['detail']['contract'] ?? [], 'deadline' => $deadline, 'url' => $url, 'recipient' => $users[$userId]];
                $email = (new Email())->from(new Address($this->sender, 'Philip Frères · Marchés'))
                    ->to($users[$userId]['email'])->subject('Échéance à venir · '.mb_substr(preg_replace('/[\r\n]+/', ' ', $market['title']), 0, 140))
                    ->html($this->twig->render('emails/marches/deadline.html.twig', $context))
                    ->text($this->twig->render('emails/marches/deadline.txt.twig', $context));
                // Reserve before SMTP, preventing duplicates across cron retries or process crashes.
                $this->store->db->insert('insight_delivery', ['id' => $id, 'tenant_id' => $tenant, 'market_id' => $market['id'], 'user_id' => $userId, 'deadline' => $market['deadline'], 'status' => 'sending', 'created_at' => InsightStore::now(), 'updated_at' => InsightStore::now()]);
                try {
                    $this->transport->send($email);
                    $this->store->db->update('insight_delivery', ['status' => 'sent', 'updated_at' => InsightStore::now()], ['id' => $id]);
                    ++$result['sent'];
                } catch (\Throwable) {
                    // An SMTP timeout may happen after acceptance: retrying blindly can duplicate mail.
                    $this->store->db->update('insight_delivery', ['status' => 'uncertain', 'updated_at' => InsightStore::now()], ['id' => $id]);
                    ++$result['uncertain'];
                }
            }
        }
        return $result;
    }
}
