<?php

declare(strict_types=1);

namespace forumaker\Statser\Tests\unit;

use Flarum\Settings\SettingsRepositoryInterface;
use forumaker\Statser\Api\Controller\PresenceHeartbeatController;
use Illuminate\Contracts\Cache\Repository as Cache;
use PHPUnit\Framework\TestCase;

/**
 * The pure helpers behind the heartbeat: address ranges (who is a Cloudflare edge, who is a local proxy), bot
 * detection and input cleaning. They decide which forwarded headers can be believed, so they get their own checks.
 */
class HeartbeatHelpersTest extends TestCase
{
    private object $helpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->helpers = new class($this->createStub(Cache::class), $this->createStub(SettingsRepositoryInterface::class)) extends PresenceHeartbeatController {
            public function inRanges(string $ip, array $cidrs): bool
            {
                return $this->ipInRanges($ip, $cidrs);
            }

            public function matches(string $ip, string $range): bool
            {
                return $this->ipMatchesRange($ip, $range);
            }

            public function privateOrReserved(string $ip): bool
            {
                return $this->isPrivateOrReserved($ip);
            }

            public function bot(string $ua): bool
            {
                return $this->isBotUserAgent($ua);
            }

            public function clean(mixed $value, int $max): ?string
            {
                return $this->sanitizeString($value, $max);
            }

            public function discussionId(mixed $value): ?int
            {
                return $this->sanitizeDiscussionId($value);
            }
        };
    }

    public function test_ipv4_cidr_ranges(): void
    {
        $this->assertTrue($this->helpers->matches('173.245.48.1', '173.245.48.0/20'));
        $this->assertTrue($this->helpers->matches('173.245.63.255', '173.245.48.0/20'));
        $this->assertFalse($this->helpers->matches('173.245.64.0', '173.245.48.0/20'));
        $this->assertFalse($this->helpers->matches('8.8.8.8', '173.245.48.0/20'));
    }

    public function test_ipv6_cidr_ranges(): void
    {
        $this->assertTrue($this->helpers->matches('2606:4700::1234', '2606:4700::/32'));
        $this->assertFalse($this->helpers->matches('2606:4701::1', '2606:4700::/32'));
    }

    public function test_an_address_family_mismatch_never_matches(): void
    {
        $this->assertFalse($this->helpers->matches('203.0.113.7', '2606:4700::/32'));
        $this->assertFalse($this->helpers->matches('2606:4700::1', '173.245.48.0/20'));
    }

    public function test_garbage_never_matches(): void
    {
        $this->assertFalse($this->helpers->matches('not-an-ip', '173.245.48.0/20'));
        $this->assertFalse($this->helpers->matches('203.0.113.7', 'nonsense/20'));
    }

    public function test_a_range_without_a_prefix_is_an_exact_match_and_zero_matches_everything(): void
    {
        $this->assertTrue($this->helpers->matches('203.0.113.7', '203.0.113.7'));
        $this->assertFalse($this->helpers->matches('203.0.113.8', '203.0.113.7'));
        $this->assertTrue($this->helpers->matches('203.0.113.8', '0.0.0.0/0'));
    }

    public function test_any_of_several_ranges_is_enough(): void
    {
        $this->assertTrue($this->helpers->inRanges('10.1.2.3', ['192.168.0.0/16', '10.0.0.0/8']));
        $this->assertFalse($this->helpers->inRanges('172.32.0.1', ['192.168.0.0/16', '10.0.0.0/8']));
    }

    public function test_private_loopback_and_reserved_space_counts_as_a_local_proxy_hop(): void
    {
        foreach (['127.0.0.1', '10.0.0.5', '172.16.4.4', '192.168.1.1', '::1', '169.254.1.1'] as $ip) {
            $this->assertTrue($this->helpers->privateOrReserved($ip), $ip);
        }

        foreach (['8.8.8.8', '93.184.216.34', '2606:4700::1'] as $ip) {
            $this->assertFalse($this->helpers->privateOrReserved($ip), $ip);
        }

        $this->assertFalse($this->helpers->privateOrReserved('not-an-ip'));
    }

    public function test_bot_detection_is_case_insensitive_and_treats_an_empty_agent_as_a_bot(): void
    {
        $this->assertTrue($this->helpers->bot('GOOGLEBOT/2.1'));
        $this->assertTrue($this->helpers->bot('Mozilla/5.0 (compatible; AhrefsBot/7.0)'));
        $this->assertTrue($this->helpers->bot(''));
        $this->assertFalse($this->helpers->bot('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/126.0 Safari/537.36'));
    }

    public function test_strings_lose_control_characters_and_are_trimmed_and_capped(): void
    {
        $this->assertSame('hello world', $this->helpers->clean("  hello\x00 world\x1F ", 80));
        $this->assertSame('abcde', $this->helpers->clean('abcdefgh', 5));
        $this->assertSame('ЯЯЯ', $this->helpers->clean('ЯЯЯЯЯ', 3), 'the cap counts characters, not bytes');
        $this->assertNull($this->helpers->clean("\x00 \x1F", 80));
        $this->assertNull($this->helpers->clean('', 80));
        $this->assertNull($this->helpers->clean(['array'], 80));
        $this->assertNull($this->helpers->clean(123, 80));
    }

    public function test_a_discussion_id_must_be_a_positive_whole_number(): void
    {
        $this->assertSame(42, $this->helpers->discussionId(42));
        $this->assertSame(42, $this->helpers->discussionId('42'));
        $this->assertNull($this->helpers->discussionId(0));
        $this->assertNull($this->helpers->discussionId(-3));
        $this->assertNull($this->helpers->discussionId('4x'));
        $this->assertNull($this->helpers->discussionId('1.5'));
        $this->assertNull($this->helpers->discussionId(null));
    }
}
