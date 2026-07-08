<?php

declare(strict_types=1);

namespace forumaker\Statser\Api\Controller;

use Flarum\Discussion\Discussion;
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

    /**
     * Both maps are read, filtered, and rewritten as a single cache entry on
     * every heartbeat (one per open tab per active visitor). A large cap
     * means a large blob getting (de)serialized on every tick — with a file
     * or database cache driver that's a contention hotspot, and even with
     * Redis it's non-trivial work to repeat that often. 500 keeps the common
     * case (a few hundred concurrent visitors) accurate without paying for
     * worst-case forums with thousands online; if you run one of those and
     * need exact counts past 500, consider moving this cache to a Redis
     * hash/sorted-set (O(1) per-entry writes) instead of raising the cap.
     */
    public const MAX_ACTIVITY_ENTRIES = 500;
    public const MAX_GUEST_ENTRIES = 500;
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

    /**
     * Cloudflare's published edge ranges (https://www.cloudflare.com/ips/).
     * CF-Connecting-IP is only honored when the request actually arrived from
     * one of these — otherwise that header is whatever the caller chose to send.
     * Last reviewed 2026-07; refresh if Cloudflare ever changes its ranges (rare).
     */
    private const CLOUDFLARE_RANGES = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
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
        $standalone = filter_var($body['standalone'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($route === null && $label === null) {
            return;
        }

        // Never trust the client's own judgement about whether a discussion is
        // private — that check runs in JS and can miss (page-load race before
        // the discussion model is set, a future frontend regression, a
        // different privacy extension we don't know about). If we can
        // independently confirm the reported discussion is private, we
        // discard whatever label the client sent, no matter what it says.
        $private = false;
        if ($route === 'discussion') {
            $discussionId = $this->sanitizeDiscussionId($body['discussionId'] ?? null);
            if ($discussionId !== null) {
                $private = $this->isDiscussionPrivate($discussionId);
            }
        }

        if ($private) {
            $label = null;
            $standalone = false;
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
            'standalone' => $standalone,
            'private' => $private,
            'ts' => $now,
        ];

        $this->cache->put(self::ACTIVITY_CACHE_KEY, $map, $intervalMin * 60 + 60);
    }

    protected function sanitizeDiscussionId(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && $value !== '' && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * Independently determines whether a discussion is a FoF Byōbu private
     * conversation, without trusting anything the client reported. Cached
     * briefly since a busy discussion can generate several heartbeats a
     * minute across its participants' open tabs.
     *
     * Fails closed: if Byōbu isn't installed there's nothing to hide, but any
     * unexpected error while checking (e.g. an incompatible future Byōbu
     * release) is treated as private — for a privacy check, wrongly hiding a
     * public discussion's title is a much smaller problem than wrongly
     * showing a private one's.
     */
    protected function isDiscussionPrivate(int $discussionId): bool
    {
        if (! class_exists(\FoF\Byobu\Discussion\Screener::class)) {
            return false;
        }

        return (bool) $this->cache->remember(
            'forumaker-statser.discussion-private.' . $discussionId,
            30,
            function () use ($discussionId) {
                try {
                    $discussion = Discussion::find($discussionId);
                    if (! $discussion) {
                        // Deleted/nonexistent — nothing to leak, and treating
                        // it as private would just show a slightly-wrong
                        // generic label for a heartbeat that's about to
                        // expire anyway.
                        return false;
                    }

                    $screener = new \FoF\Byobu\Discussion\Screener();

                    return $screener->fromDiscussion($discussion)->isPrivate();
                } catch (\Throwable $e) {
                    return true;
                }
            }
        );
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

    /**
     * Resolves the client's IP address, trusting forwarded headers only when
     * the immediate connecting peer is entitled to set them — no admin
     * configuration required.
     *
     * Behind nginx, Cloudflare, or any load balancer, REMOTE_ADDR is the
     * proxy's own address rather than the visitor's — this made every guest
     * share the same rate-limit bucket and online-guest fingerprint. We can't
     * blindly trust X-Forwarded-For/CF-Connecting-IP though, since anyone
     * hitting the origin directly could forge them to bypass the rate limit
     * and inflate the guest count. Resolution order:
     *
     *   1. Peer is a Cloudflare edge IP → CF-Connecting-IP is the genuine
     *      visitor. (If a front nginx already rewrites REMOTE_ADDR via
     *      ngx_http_realip_module, this branch is simply skipped — REMOTE_ADDR
     *      already holds the visitor, same result.)
     *   2. Peer is private/loopback → a local reverse proxy (nginx, Docker,
     *      etc. on the same host/network); its X-Forwarded-For first hop is
     *      the client. A direct public attacker has a public REMOTE_ADDR and
     *      never reaches this branch.
     *   3. Otherwise the peer IS the client — use REMOTE_ADDR, never a header.
     */
    protected function resolveClientIp(ServerRequestInterface $request): string
    {
        $remoteAddr = trim((string) ($request->getServerParams()['REMOTE_ADDR'] ?? ''));

        if ($remoteAddr !== '' && $this->ipInRanges($remoteAddr, self::CLOUDFLARE_RANGES)) {
            $cfIp = trim($request->getHeaderLine('CF-Connecting-IP'));
            if ($cfIp !== '' && filter_var($cfIp, FILTER_VALIDATE_IP)) {
                return $cfIp;
            }
        }

        if ($remoteAddr !== '' && $this->isPrivateOrReserved($remoteAddr)) {
            $forwardedFor = $request->getHeaderLine('X-Forwarded-For');
            if ($forwardedFor !== '') {
                $candidate = trim(explode(',', $forwardedFor)[0]);
                if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP)) {
                    return $candidate;
                }
            }
        }

        return $remoteAddr;
    }

    /** True if $ip falls inside any of the given CIDR blocks (IPv4 or IPv6). */
    protected function ipInRanges(string $ip, array $cidrs): bool
    {
        foreach ($cidrs as $cidr) {
            if ($this->ipMatchesRange($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    protected function ipMatchesRange(string $ip, string $range): bool
    {
        if (! str_contains($range, '/')) {
            return hash_equals($range, $ip);
        }

        [$subnet, $bits] = explode('/', $range, 2);
        $bits = (int) $bits;

        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);

        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $maxBits = strlen($ipBin) * 8;
        $bits = max(0, min($maxBits, $bits));

        $bytes = intdiv($bits, 8);
        $remainderBits = $bits % 8;

        if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
            return false;
        }

        if ($remainderBits === 0) {
            return true;
        }

        $mask = ~(0xFF >> $remainderBits) & 0xFF;

        return (ord($ipBin[$bytes]) & $mask) === (ord($subnetBin[$bytes]) & $mask);
    }

    /** True for RFC1918 / loopback / other reserved space — i.e. a local proxy hop. */
    protected function isPrivateOrReserved(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false
            && filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) === false;
    }
}
