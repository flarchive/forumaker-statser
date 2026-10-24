<?php

declare(strict_types=1);

namespace forumaker\Statser;

use Carbon\Carbon;
use Flarum\Api\Context;
use Flarum\Api\Schema;
use Flarum\Discussion\Discussion;
use Flarum\Post\CommentPost;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use forumaker\Statser\Api\Controller\PresenceHeartbeatController;
use Illuminate\Contracts\Cache\Repository as Cache;

class ForumResourceFields
{
    public const MAX_DISPLAYED_ONLINE = 300;

    protected ?array $onlineUserDataCache = null;
    protected ?string $onlineUserDataCacheKey = null;
    protected ?array $statsCache = null;
    protected ?array $activityMapCache = null;

    public function __construct(
        protected Cache $cache,
        protected SettingsRepositoryInterface $settings
    ) {
    }

    public function __invoke(): array
    {
        return [
            Schema\Boolean::make('statserCanViewOnline')
                ->get(fn ($model, Context $context) => $this->isOnlineEnabled()
                    && $context->getActor()->hasPermission('forumaker-statser.viewOnlineUsers')),

            Schema\Integer::make('statserTotalOnline')
                ->visible(fn ($model, Context $context) => $this->isOnlineEnabled()
                    && $context->getActor()->hasPermission('forumaker-statser.viewOnlineUsers'))
                ->get(fn ($model, Context $context) => $this->getOnlineUserData($context->getActor())['total'] ?? 0),

            Schema\Integer::make('statserHiddenOnline')
                ->visible(fn ($model, Context $context) => $this->isOnlineEnabled()
                    && $this->isHiddenUsersEnabled()
                    && $context->getActor()->hasPermission('forumaker-statser.viewOnlineUsers'))
                ->get(fn ($model, Context $context) => $this->getOnlineUserData($context->getActor())['hidden'] ?? 0),

            Schema\Relationship\ToMany::make('statserOnlineUsers')
                ->type('users')
                ->includable()
                ->visible(fn ($model, Context $context) => $this->isOnlineEnabled()
                    && $context->getActor()->hasPermission('forumaker-statser.viewOnlineUsers'))
                ->get(fn ($model, Context $context) => $this->getOnlineUserModels($context->getActor())),

            Schema\Str::make('statserActivityMap')
                ->visible(fn ($model, Context $context) => $this->isOnlineEnabled()
                    && (bool) $this->settings->get('forumaker-statser.show_current_page', true)
                    && $context->getActor()->hasPermission('forumaker-statser.viewCurrentPage'))
                ->get(fn ($model, Context $context) => json_encode(
                    $this->getActivityMap($context->getActor()),
                    JSON_UNESCAPED_UNICODE
                )),

            Schema\Boolean::make('statserShowOnlineGuests')
                ->get(fn () => $this->isOnlineGuestsEnabled()),

            Schema\Boolean::make('statserIncludeGuestsInTotal')
                ->visible(fn ($model, Context $context) => $context->getActor()->hasPermission('forumaker-statser.viewOnlineUsers'))
                ->get(fn () => $this->isOnlineGuestsEnabled()
                    && (bool) $this->settings->get('forumaker-statser.include_guests_in_total', true)),

            Schema\Integer::make('statserOnlineGuests')
                ->visible(fn ($model, Context $context) => $this->isOnlineGuestsEnabled()
                    && $context->getActor()->hasPermission('forumaker-statser.viewOnlineUsers'))
                ->get(fn () => $this->getOnlineGuestsCount()),

            Schema\Integer::make('statserDiscussionsCount')
                ->visible(fn ($model, Context $context) => (bool) $this->settings->get('forumaker-statser.show_discussions_count', true)
                    && $context->getActor()->hasPermission('forumaker-statser.viewStats.discussionsCount'))
                ->get(fn ($model, Context $context) => $this->getStats()['discussion_count'] ?? 0),

            Schema\Integer::make('statserPostsCount')
                ->visible(fn ($model, Context $context) => (bool) $this->settings->get('forumaker-statser.show_posts_count', true)
                    && $context->getActor()->hasPermission('forumaker-statser.viewStats.postsCount'))
                ->get(fn ($model, Context $context) => $this->getStats()['post_count'] ?? 0),

            Schema\Integer::make('statserUsersCount')
                ->visible(fn ($model, Context $context) => (bool) $this->settings->get('forumaker-statser.show_users_count', true)
                    && $context->getActor()->hasPermission('forumaker-statser.viewStats.usersCount'))
                ->get(fn ($model, Context $context) => $this->getStats()['user_count'] ?? 0),

            Schema\Relationship\ToOne::make('statserLatestUser')
                ->type('users')
                ->includable()
                ->visible(fn ($model, Context $context) => (bool) $this->settings->get('forumaker-statser.show_latest_registration', true)
                    && $context->getActor()->hasPermission('forumaker-statser.viewStats.latestMember'))
                ->get(fn ($model, Context $context) => $this->getLatestUser()),

            Schema\Boolean::make('statserEnableHeartbeat')
                ->get(fn () => (bool) $this->settings->get('forumaker-statser.enable_heartbeat', true)),

            Schema\Integer::make('statserHeartbeatInterval')
                ->get(fn () => max(15, (int) $this->settings->get('forumaker-statser.heartbeat_interval', 45))),

            Schema\Boolean::make('statserShowCurrentPage')
                ->get(fn ($model, Context $context) => $this->isOnlineEnabled()
                    && (bool) $this->settings->get('forumaker-statser.show_current_page', true)
                    && $context->getActor()->hasPermission('forumaker-statser.viewCurrentPage')),

            Schema\Boolean::make('statserCanReportCurrentPage')
                ->get(fn () => $this->isOnlineEnabled()
                    && (bool) $this->settings->get('forumaker-statser.show_current_page', true)),

            Schema\Integer::make('statserMaxAvatars')
                ->get(fn () => max(1, (int) $this->settings->get('forumaker-statser.max_avatars', 24))),
        ];
    }

    protected function isOnlineEnabled(): bool
    {
        return (bool) $this->settings->get('forumaker-statser.show_online_users', true);
    }

    protected function isOnlineGuestsEnabled(): bool
    {
        return $this->isOnlineEnabled()
            && (bool) $this->settings->get('forumaker-statser.show_online_guests', true);
    }

    protected function isHiddenUsersEnabled(): bool
    {
        return (bool) $this->settings->get('forumaker-statser.show_hidden_users', true);
    }

    protected function getOnlineGuestsCount(): int
    {
        $guests = $this->cache->get(PresenceHeartbeatController::GUEST_CACHE_KEY, []);
        if (! is_array($guests) || empty($guests)) {
            return 0;
        }

        $intervalMin = max(1, (int) $this->settings->get('forumaker-statser.last_seen_interval', 5));
        $cutoff = time() - $intervalMin * 60;
        $count = 0;

        foreach ($guests as $ts) {
            if ($ts > $cutoff) {
                $count++;
            }
        }

        return $count;
    }

    protected function getOnlineUserData(User $actor): array
    {
        $canSeeHidden = $actor->hasPermission('user.viewLastSeenAt');
        $cacheKey = $canSeeHidden
            ? 'forumaker-statser.online-users.admin'
            : 'forumaker-statser.online-users.regular';

        if ($this->onlineUserDataCacheKey === $cacheKey && $this->onlineUserDataCache !== null) {
            return $this->onlineUserDataCache;
        }

        $ttl = max(1, (int) $this->settings->get('forumaker-statser.online_users_cache_ttl', 30));
        $interval = max(1, (int) $this->settings->get('forumaker-statser.last_seen_interval', 5));
        $maxUsers = self::MAX_DISPLAYED_ONLINE;

        $data = $this->cache->remember($cacheKey, $ttl, function () use ($canSeeHidden, $interval, $maxUsers) {
            $allOnlineQuery = User::query()
                ->where('last_seen_at', '>', Carbon::now()->subMinutes($interval));

            $totalAll = (clone $allOnlineQuery)->count();

            if ($canSeeHidden) {
                $users = $allOnlineQuery->orderBy('last_seen_at', 'desc')
                    ->limit($maxUsers)
                    ->get();

                return [
                    'users' => $users->map(fn (User $u) => $u->getAttributes())->all(),
                    'total' => $totalAll,
                    'hidden' => 0,
                ];
            }

            $visibleQuery = clone $allOnlineQuery;
            $visibleQuery->where(function ($q) {
                $q->whereNull('preferences')
                    ->orWhereNull('preferences->discloseOnline')
                    ->orWhere('preferences->discloseOnline', '!=', false);
            });

            $totalVisible = (clone $visibleQuery)->count();
            $hidden = $totalAll - $totalVisible;

            $users = $visibleQuery->orderBy('last_seen_at', 'desc')
                ->limit($maxUsers)
                ->get();

            return [
                'users' => $users->map(fn (User $u) => $u->getAttributes())->all(),
                'total' => $totalAll,
                'hidden' => $hidden,
            ];
        }) ?: ['users' => [], 'total' => 0, 'hidden' => 0];

        $this->onlineUserDataCacheKey = $cacheKey;
        $this->onlineUserDataCache = $data;

        return $data;
    }

    protected function getOnlineUserModels(User $actor): array
    {
        $data = $this->getOnlineUserData($actor);

        if (empty($data['users'])) {
            return [];
        }

        $models = [];

        foreach ($data['users'] as $attributes) {
            $user = new User();
            $user->setRawAttributes($attributes, true);
            $user->exists = true;
            $models[] = $user;
        }

        return $models;
    }

    protected function getActivityMap(User $actor): array
    {
        if ($this->activityMapCache !== null) {
            return $this->activityMapCache;
        }

        $raw = $this->cache->get(PresenceHeartbeatController::ACTIVITY_CACHE_KEY, []);
        if (! is_array($raw) || empty($raw)) {
            return $this->activityMapCache = [];
        }

        $intervalMin = max(1, (int) $this->settings->get('forumaker-statser.last_seen_interval', 5));
        $cutoff = time() - $intervalMin * 60;

        $onlineIds = array_column($this->getOnlineUserData($actor)['users'] ?? [], 'id');
        $onlineIds = array_flip($onlineIds);
        $map = [];

        foreach ($raw as $userId => $entry) {
            if (! is_array($entry) || ($entry['ts'] ?? 0) <= $cutoff) {
                continue;
            }
            if (! isset($onlineIds[(int) $userId])) {
                continue;
            }

            $map[(string) $userId] = [
                'route' => $entry['route'] ?? null,
                'label' => $entry['label'] ?? null,
                'standalone' => (bool) ($entry['standalone'] ?? false),
                'private' => (bool) ($entry['private'] ?? false),
            ];
        }

        return $this->activityMapCache = $map;
    }

    protected function getStats(): array
    {
        if ($this->statsCache !== null) {
            return $this->statsCache;
        }

        $ttl = max(0, (int) $this->settings->get('forumaker-statser.stats_cache_duration', 600));

        if ($ttl === 0) {
            $this->statsCache = $this->buildStats();
        } else {
            $this->statsCache = $this->cache->remember(
                'forumaker-statser.stats',
                $ttl,
                fn () => $this->buildStats()
            ) ?: [];
        }

        return $this->statsCache;
    }

    protected function buildStats(): array
    {
        $ignorePrivate = (bool) $this->settings->get('forumaker-statser.ignore_private_discussions', false);
        $latestUser = User::query()->orderBy('joined_at', 'desc')->first();

        return [
            'discussion_count' => $ignorePrivate
                ? Discussion::query()->where('is_private', false)->count()
                : Discussion::query()->count(),
            'post_count' => CommentPost::query()->count(),
            'user_count' => User::query()->count(),
            'latest_user' => $latestUser ? $latestUser->getAttributes() : null,
        ];
    }

    protected function getLatestUser(): ?User
    {
        $stats = $this->getStats();
        $attributes = $stats['latest_user'] ?? null;

        if (! $attributes) {
            return null;
        }

        $user = new User();
        $user->setRawAttributes($attributes, true);
        $user->exists = true;

        return $user;
    }
}
