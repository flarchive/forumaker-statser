<?php

declare(strict_types=1);

namespace forumaker\Statser\Tests\integration;

use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use forumaker\Statser\Api\Controller\PresenceHeartbeatController;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * What GET /api (the forum resource) exposes to whom: online data only to those allowed to see it, and never when
 * the "online users" switch is off.
 */
class ForumResourceTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    public function setUp(): void
    {
        parent::setUp();

        $this->extension('forumaker-statser');

        $this->prepareDatabase([
            'users' => [$this->normalUser()],
        ]);
    }

    /** @return array<string, mixed> the forum resource's attributes as seen by $userId (null = guest) */
    private function forumAttributes(?int $userId): array
    {
        $options = $userId ? ['authenticatedAs' => $userId] : [];
        $response = $this->send($this->request('GET', '/api', $options));

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return json_decode((string) $response->getBody(), true)['data']['attributes'];
    }

    public function test_by_default_everyone_can_view_online_data(): void
    {
        // The extension's migration grants viewOnlineUsers to the guest group, which every visitor inherits.
        foreach ([null, 2] as $userId) {
            $attributes = $this->forumAttributes($userId);

            $this->assertTrue($attributes['statserCanViewOnline']);
            $this->assertArrayHasKey('statserTotalOnline', $attributes);
        }
    }

    public function test_without_the_permission_nobody_gets_online_numbers(): void
    {
        $this->app();
        $this->database()->table('group_permission')->where('permission', 'forumaker-statser.viewOnlineUsers')->delete();

        foreach ([null, 2] as $userId) {
            $attributes = $this->forumAttributes($userId);

            $this->assertFalse($attributes['statserCanViewOnline']);
            $this->assertArrayNotHasKey('statserTotalOnline', $attributes);
            $this->assertArrayNotHasKey('statserOnlineGuests', $attributes);
        }
    }

    public function test_switching_online_users_off_hides_it_even_from_those_with_the_permission(): void
    {
        $this->setting('forumaker-statser.show_online_users', '0');

        $attributes = $this->forumAttributes(2);

        $this->assertFalse($attributes['statserCanViewOnline']);
        $this->assertArrayNotHasKey('statserTotalOnline', $attributes);
    }

    public function test_the_guest_count_is_exposed_to_those_allowed(): void
    {
        $this->app()->getContainer()->make(Cache::class)->put(
            PresenceHeartbeatController::GUEST_CACHE_KEY,
            ['aaaa' => time(), 'bbbb' => time(), 'stale' => time() - 3600],
            600
        );

        $attributes = $this->forumAttributes(2);

        $this->assertSame(2, $attributes['statserOnlineGuests'], 'only the guests seen within the interval are counted');
    }
}
