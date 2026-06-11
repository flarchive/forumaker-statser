<?php

declare(strict_types=1);

namespace forumaker\Statser\Api\Controller;

use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Cache\Repository as Cache;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class PresenceHeartbeatController implements RequestHandlerInterface
{
    public const ACTIVITY_CACHE_KEY = 'forumaker-statser.activity';
    public const GUEST_CACHE_KEY = 'forumaker-statser.online-guests';
    public const MAX_ACTIVITY_ENTRIES = 2000;
    public const MAX_GUEST_ENTRIES = 2000;
    public const GUEST_RATE_LIMIT_PER_MIN = 6;
    public const MAX_LABEL_LENGTH = 120;

    private const BOT_UA_TOKENS = [
        'bot', 'crawler', 'spider', 'scraper', 'slurp',
        'wget', 'curl', 'python-requests', 'go-http-client', 'java/',
        'googlebot', 'bingbot', 'yandexbot', 'baiduspider', 'duckduckbot',
        'facebookexternalhit', 'twitterbot', 'linkedinbot', 'whatsapp',
        'semrushbot', 'ahrefsbot', 'mj12bot', 'dotbot', 'petalbot',
        'sogou', 'exabot', 'ia_archiver', 'archive.org_bot',
        'nmap', 'masscan', 'zgrab', 'nuclei',
    ];

    public function __construct(
        protected Cache $cache,
        protected SettingsRepositoryInterface $settings
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (! (bool) $this->settings->get('forumaker-statser.enable_heartbeat', true)) {
            return new EmptyResponse(204);
        }

        $actor = RequestUtil::getActor($request);

        if ($actor->isGuest()) {
            $this->handleGuest($request);
        } else {
            $this->handleMember($request, (int) $actor->id);
        }

        return new EmptyResponse(204);
    }

    protected function handleMember(ServerRequestInterface $request, int $userId): void
    {
        if (! (bool) $this->settings->get('forumaker-statser.show_current_page', true)) {
            return;
        }

        $body = (array) $request->getParsedBody();
        $route = $this->sanitizeString($body['route'] ?? null, 80);
        $label = $this->sanitizeString($body['label'] ?? null, self::MAX_LABEL_LENGTH);

        if ($route === null && $label === null) {
            return;
        }

        $intervalMin = max(1, (int) $this->settings->get('forumaker-statser.last_seen_interval', 5));
        $now = time();
        $cutoff = $now - $intervalMin * 60;

        $map = $this->cache->get(self::ACTIVITY_CACHE_KEY, []);
        if (! is_array($map)) {
            $map = [];
        }

        $map = array_filter($map, fn ($entry) => is_array($entry) && ($entry['ts'] ?? 0) > $cutoff);

        if (! isset($map[$userId]) && count($map) >= self::MAX_ACTIVITY_ENTRIES) {
            uasort($map, fn ($a, $b) => ($a['ts'] ?? 0) <=> ($b['ts'] ?? 0));
            $map = array_slice($map, 1, null, true);
        }

        $map[$userId] = [
            'route' => $route,
            'label' => $label,
            'ts' => $now,
        ];

        $this->cache->put(self::ACTIVITY_CACHE_KEY, $map, $intervalMin * 60 + 60);
    }

    protected function handleGuest(ServerRequestInterface $request): void
    {
        if (! (bool) $this->settings->get('forumaker-statser.show_online_users', true)
            || ! (bool) $this->settings->get('forumaker-statser.show_online_guests', true)) {
            return;
        }

        $ua = $request->getHeaderLine('User-Agent');

        if ((bool) $this->settings->get('forumaker-statser.exclude_bots', true)
            && $this->isBotUserAgent($ua)) {
            return;
        }

        $ip = $this->resolveClientIp($request);

        $rlKey = 'forumaker-statser.guest-rl.' . hash('sha256', $ip);
        $count = (int) $this->cache->get($rlKey, 0);
        if ($count >= self::GUEST_RATE_LIMIT_PER_MIN) {
            return;
        }
        $this->cache->put($rlKey, $count + 1, 60);

        $hash = substr(hash('sha256', $ip . '|' . $ua), 0, 16);

        $intervalMin = max(1, (int) $this->settings->get('forumaker-statser.last_seen_interval', 5));
        $now = time();
        $cutoff = $now - $intervalMin * 60;

        $guests = $this->cache->get(self::GUEST_CACHE_KEY, []);
        if (! is_array($guests)) {
            $guests = [];
        }

        $guests = array_filter($guests, fn ($ts) => $ts > $cutoff);

        if (! isset($guests[$hash]) && count($guests) >= self::MAX_GUEST_ENTRIES) {
            asort($guests, SORT_NUMERIC);
            $guests = array_slice($guests, 1, null, true);
        }
        $guests[$hash] = $now;

        $this->cache->put(self::GUEST_CACHE_KEY, $guests, $intervalMin * 60 + 60);
    }

    protected function isBotUserAgent(string $ua): bool
    {
        if ($ua === '') {
            return true;
        }

        $lower = strtolower($ua);

        foreach (self::BOT_UA_TOKENS as $token) {
            if (str_contains($lower, $token)) {
                return true;
            }
        }

        return false;
    }

    protected function sanitizeString(mixed $value, int $maxLength): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '');

        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, $maxLength);
    }

    protected function resolveClientIp(ServerRequestInterface $request): string
    {
        $server = $request->getServerParams();

        return $server['REMOTE_ADDR'] ?? '';
    }
}
