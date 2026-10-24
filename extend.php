<?php

declare(strict_types=1);

namespace forumaker\Statser;

use Flarum\Api\Endpoint;
use Flarum\Api\Resource\ForumResource;
use Flarum\Extend;
use forumaker\Statser\Api\Controller\PresenceHeartbeatController;

return [
    new Extend\Locales(__DIR__ . '/locale'),

    (new Extend\Frontend('forum'))
        ->css(__DIR__ . '/resources/less/forum.less')
        ->js(__DIR__ . '/js/dist/forum.js'),

    (new Extend\Frontend('admin'))
        ->css(__DIR__ . '/resources/less/admin.less')
        ->js(__DIR__ . '/js/dist/admin.js'),

    (new Extend\Settings())
        ->default('forumaker-statser.show_online_users', true)
        ->default('forumaker-statser.show_online_guests', true)
        ->default('forumaker-statser.show_hidden_users', true)
        ->default('forumaker-statser.include_guests_in_total', true)
        ->default('forumaker-statser.exclude_bots', true)
        ->default('forumaker-statser.show_discussions_count', true)
        ->default('forumaker-statser.show_posts_count', true)
        ->default('forumaker-statser.show_users_count', true)
        ->default('forumaker-statser.show_latest_registration', true)
        ->default('forumaker-statser.show_current_page', true)
        ->default('forumaker-statser.last_seen_interval', 5)
        ->default('forumaker-statser.online_users_cache_ttl', 30)
        ->default('forumaker-statser.stats_cache_duration', 600)
        ->default('forumaker-statser.enable_heartbeat', true)
        ->default('forumaker-statser.heartbeat_interval', 45)
        ->default('forumaker-statser.ignore_private_discussions', false)
        ->default('forumaker-statser.max_avatars', 24),

    (new Extend\ApiResource(ForumResource::class))
        ->fields(ForumResourceFields::class)
        ->endpoint(Endpoint\Show::class, function (Endpoint\Show $endpoint) {
            return $endpoint->addDefaultInclude(['statserOnlineUsers', 'statserLatestUser']);
        }),

    (new Extend\Routes('api'))
        ->post('/statser/heartbeat', 'statser.heartbeat', PresenceHeartbeatController::class),

    (new Extend\Event())
        ->subscribe(Listener\FlushCaches::class),
];
