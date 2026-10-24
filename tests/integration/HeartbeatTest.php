<?php

declare(strict_types=1);

namespace forumaker\Statser\Tests\integration;

use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use forumaker\Statser\Api\Controller\PresenceHeartbeatController;
use Illuminate\Contracts\Cache\Repository as Cache;
use Laminas\Diactoros\ServerRequest;
use Psr\Http\Message\ResponseInterface;

/**
 * POST /api/statser/heartbeat — what the widget's "who is online" and "what are they looking at" data is built from.
 * Members report the page they are on; guests are only counted, and counting them must not be gameable (bots, one
 * address flooding the counter, forged forwarding headers).
 */
class HeartbeatTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    private const BROWSER = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36';

    public function setUp(): void
    {
        parent::setUp();

        $this->extension('forumaker-statser');

        $this->prepareDatabase([
            'users' => [$this->normalUser()],
        ]);
    }

    private function cache(): Cache
    {
        return $this->app()->getContainer()->make(Cache::class);
    }

    /** @return array<int, array<string, mixed>> */
    private function activity(): array
    {
        return (array) $this->cache()->get(PresenceHeartbeatController::ACTIVITY_CACHE_KEY, []);
    }

    /** @return array<string, int> */
    private function guests(): array
    {
        return (array) $this->cache()->get(PresenceHeartbeatController::GUEST_CACHE_KEY, []);
    }

    /**
     * @param array<string, mixed> $options userId: authenticate as that user; ip: the connecting peer (REMOTE_ADDR);
     *                                       userAgent; headers: extra request headers
     */
    private function heartbeat(array $body, array $options = []): ResponseInterface
    {
        $request = new ServerRequest(['REMOTE_ADDR' => $options['ip'] ?? '203.0.113.7'], [], '/api/statser/heartbeat', 'POST');
        $request = $this->requestWithJsonBody($request, $body)
            ->withAttribute('bypassCsrfToken', true)
            ->withHeader('User-Agent', $options['userAgent'] ?? self::BROWSER);

        foreach ($options['headers'] ?? [] as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if (isset($options['userId'])) {
            $request = $this->requestAsUser($request, $options['userId']);
        }

        return $this->send($request);
    }

    // ── switches ─────────────────────────────────────────────────────────

    public function test_heartbeat_disabled_in_settings_records_nothing(): void
    {
        $this->setting('forumaker-statser.enable_heartbeat', '0');

        $this->assertSame(204, $this->heartbeat(['route' => 'index'], ['userId' => 2])->getStatusCode());
        $this->assertSame(204, $this->heartbeat([])->getStatusCode());

        $this->assertSame([], $this->activity());
        $this->assertSame([], $this->guests());
    }

    // ── members ──────────────────────────────────────────────────────────

    public function test_a_member_heartbeat_records_the_page_they_are_on(): void
    {
        $response = $this->heartbeat(['route' => 'discussion', 'label' => 'How to play', 'standalone' => true], ['userId' => 2]);

        $this->assertSame(204, $response->getStatusCode());

        $entry = $this->activity()[2] ?? null;
        $this->assertNotNull($entry);
        $this->assertSame('discussion', $entry['route']);
        $this->assertSame('How to play', $entry['label']);
        $this->assertTrue($entry['standalone']);
        $this->assertFalse($entry['private']);
    }

    public function test_a_second_heartbeat_replaces_the_first_instead_of_adding_a_second_entry(): void
    {
        $this->heartbeat(['route' => 'index', 'label' => 'Home'], ['userId' => 2]);
        $this->heartbeat(['route' => 'tags', 'label' => 'Tags'], ['userId' => 2]);

        $this->assertCount(1, $this->activity());
        $this->assertSame('tags', $this->activity()[2]['route']);
    }

    public function test_control_characters_are_stripped_and_labels_are_capped(): void
    {
        $this->heartbeat(['route' => "dis\x00cus\x1Fsion", 'label' => str_repeat('я', 500)], ['userId' => 2]);

        $entry = $this->activity()[2];

        $this->assertSame('discussion', $entry['route']);
        $this->assertSame(PresenceHeartbeatController::MAX_LABEL_LENGTH, mb_strlen($entry['label']));
    }

    public function test_a_heartbeat_without_route_and_label_is_ignored(): void
    {
        $this->heartbeat(['route' => '   ', 'label' => "\x00"], ['userId' => 2]);
        $this->heartbeat(['standalone' => true], ['userId' => 2]);

        $this->assertSame([], $this->activity());
    }

    public function test_current_page_tracking_can_be_switched_off(): void
    {
        $this->setting('forumaker-statser.show_current_page', '0');

        $this->heartbeat(['route' => 'index'], ['userId' => 2]);

        $this->assertSame([], $this->activity());
    }

    // ── guests ───────────────────────────────────────────────────────────

    public function test_a_guest_is_counted_once_per_address_and_browser(): void
    {
        $this->heartbeat([]);
        $this->heartbeat([]);

        $this->assertCount(1, $this->guests());
        $this->assertSame([], $this->activity(), 'guests never report a page');
    }

    public function test_known_bots_and_empty_user_agents_are_not_counted(): void
    {
        foreach (['Googlebot/2.1 (+http://www.google.com/bot.html)', 'curl/8.5.0', 'python-requests/2.31', 'YandexBot/3.0', ''] as $agent) {
            $this->heartbeat([], ['userAgent' => $agent, 'ip' => '198.51.100.' . random_int(1, 200)]);
        }

        $this->assertSame([], $this->guests());
    }

    public function test_bots_are_counted_when_bot_filtering_is_off(): void
    {
        $this->setting('forumaker-statser.exclude_bots', '0');

        $this->heartbeat([], ['userAgent' => 'Googlebot/2.1']);

        $this->assertCount(1, $this->guests());
    }

    public function test_guests_are_not_counted_when_online_guests_are_hidden(): void
    {
        $this->setting('forumaker-statser.show_online_guests', '0');

        $this->heartbeat([]);

        $this->assertSame([], $this->guests());
    }

    public function test_one_address_cannot_flood_the_counter(): void
    {
        for ($i = 1; $i <= PresenceHeartbeatController::GUEST_RATE_LIMIT_PER_MIN + 4; $i++) {
            $this->heartbeat([], ['userAgent' => self::BROWSER . " build-$i"]);
        }

        $this->assertCount(PresenceHeartbeatController::GUEST_RATE_LIMIT_PER_MIN, $this->guests());
    }

    public function test_a_forged_forwarding_header_from_a_public_address_is_ignored(): void
    {
        // The peer is a public address, so X-Forwarded-For is whatever the caller chose to send.
        $this->heartbeat([], ['ip' => '203.0.113.7', 'headers' => ['X-Forwarded-For' => '198.51.100.1']]);
        $this->heartbeat([], ['ip' => '203.0.113.7', 'headers' => ['X-Forwarded-For' => '198.51.100.2']]);

        $this->assertCount(1, $this->guests());
    }

    public function test_behind_a_local_proxy_the_forwarded_address_tells_visitors_apart(): void
    {
        $this->heartbeat([], ['ip' => '127.0.0.1', 'headers' => ['X-Forwarded-For' => '198.51.100.1, 10.0.0.2']]);
        $this->heartbeat([], ['ip' => '127.0.0.1', 'headers' => ['X-Forwarded-For' => '198.51.100.2']]);
        $this->heartbeat([], ['ip' => '127.0.0.1', 'headers' => ['X-Forwarded-For' => 'not-an-address']]);

        $this->assertCount(3, $this->guests(), 'two forwarded visitors, plus the proxy itself for the unusable header');
    }

    public function test_cloudflare_header_is_only_trusted_from_a_cloudflare_address(): void
    {
        // From a Cloudflare edge: CF-Connecting-IP is the visitor.
        $this->heartbeat([], ['ip' => '173.245.48.10', 'headers' => ['CF-Connecting-IP' => '198.51.100.1']]);
        $this->heartbeat([], ['ip' => '173.245.48.10', 'headers' => ['CF-Connecting-IP' => '198.51.100.2']]);
        // From anywhere else it is just a header the caller made up.
        $this->heartbeat([], ['ip' => '203.0.113.9', 'headers' => ['CF-Connecting-IP' => '198.51.100.3']]);
        $this->heartbeat([], ['ip' => '203.0.113.9', 'headers' => ['CF-Connecting-IP' => '198.51.100.4']]);

        $this->assertCount(3, $this->guests());
    }
}
