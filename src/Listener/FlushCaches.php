<?php

declare(strict_types=1);

namespace forumaker\Statser\Listener;

use Flarum\Discussion\Event\Deleted as DiscussionDeleted;
use Flarum\Discussion\Event\Started as DiscussionStarted;
use Flarum\Post\Event\Deleted as PostDeleted;
use Flarum\Post\Event\Posted;
use Flarum\User\Event\Deleted as UserDeleted;
use Flarum\User\Event\Registered;
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
        $events->listen(Registered::class, [$this, 'flushAll']);
        $events->listen(UserDeleted::class, [$this, 'flushAll']);
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
