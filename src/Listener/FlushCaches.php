<?php

declare(strict_types=1);

namespace forumaker\Statser\Listener;

use Flarum\Discussion\Event\Deleted as DiscussionDeleted;
use Flarum\Discussion\Event\Started as DiscussionStarted;
use Flarum\Post\Event\Deleted as PostDeleted;
use Flarum\Post\Event\Posted;
use Flarum\User\Event\Deleted as UserDeleted;
use Flarum\User\Event\Registered;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Events\Dispatcher;

class FlushCaches
{
    public function __construct(
        protected Cache $cache
    ) {
    }

    public function subscribe(Dispatcher $events): void
    {
        // User events — flush everything (stats + both online-users variants).
        $events->listen(Registered::class, [$this, 'flushAll']);
        $events->listen(UserDeleted::class, [$this, 'flushAll']);

        // Also hook the low-level Eloquent model events. Some extensions
        // (e.g. fof/anti-spam's spamblock) delete users via a raw
        // `$user->delete()`, which fires Eloquent's "deleted" event but NOT
        // Flarum's domain-level User\Event\Deleted — so the listener above
        // never runs, and a stale "ghost" user is left in the stats/online
        // cache. Core then serves that row-less model and 500s the whole
        // forum document via loadAggregate(). These cover every direct-
        // Eloquent create/delete path and flush synchronously in the same
        // request, same as the domain-event listeners above.
        $events->listen('eloquent.deleted: ' . User::class, [$this, 'flushAll']);
        $events->listen('eloquent.created: ' . User::class, [$this, 'flushAll']);

        // Discussion/post events — flush stats cache only.
        $events->listen(DiscussionStarted::class, [$this, 'flushStats']);
        $events->listen(DiscussionDeleted::class, [$this, 'flushStats']);
        $events->listen(Posted::class, [$this, 'flushStats']);
        $events->listen(PostDeleted::class, [$this, 'flushStats']);
    }

    public function flushAll(mixed $event): void
    {
        $this->cache->forget('forumaker-statser.stats');
        $this->cache->forget('forumaker-statser.online-users.admin');
        $this->cache->forget('forumaker-statser.online-users.regular');
    }

    public function flushStats(mixed $event): void
    {
        $this->cache->forget('forumaker-statser.stats');
    }
}
