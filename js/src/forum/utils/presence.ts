import app from 'flarum/forum/app';
import extractText from 'flarum/common/utils/extractText';

let inflightRefresh: Promise<void> | null = null;

function hasVisibleWidgetData(): boolean {
  const f = app.forum;
  if (!f) return false;
  if (f.attribute('statserCanViewOnline')) return true;
  if (f.attribute('statserDiscussionsCount') != null) return true;
  if (f.attribute('statserPostsCount') != null) return true;
  if (f.attribute('statserUsersCount') != null) return true;
  try {
    if ((f as any).statserLatestUser?.()) return true;
  } catch (e) {}
  return false;
}

export function refreshForumData(): Promise<void> {
  if (!hasVisibleWidgetData()) return Promise.resolve();
  if (inflightRefresh) return inflightRefresh;

  inflightRefresh = app.store
    .find('forums', {
      include: 'statserOnlineUsers,statserLatestUser',
      'fields[users]': 'username,displayName,avatarUrl,slug',
    })
    .catch(() => {})
    .then(() => {
      inflightRefresh = null;
      m.redraw();
    });

  return inflightRefresh;
}

export function describeCurrentRoute(): { route: string; label: string } | null {
  try {
    const current = app.current;
    if (!current || typeof current.get !== 'function') return null;

    const routeName: string | undefined = current.get('routeName');
    if (!routeName) return null;

    const pre = 'forumaker-statser.forum.routes.';
    const base = routeName.split('.')[0];

    switch (base) {
      case 'index':
        return { route: routeName, label: extractText(app.translator.trans(pre + 'index')) };

      case 'discussion': {
        const discussion = current.get('discussion');
        const rawTitle =
          discussion?.title?.() ??
          discussion?.attribute?.('title') ??
          (discussion as any)?.data?.attributes?.title ??
          (document.title?.replace(/\s*[|·—–]\s*.+$/, '').trim() || null);
        const title = rawTitle ? rawTitle.replace(/\s+[-–]\s+\S+$/, '').trim() || rawTitle : null;
        return {
          route: routeName,
          label: title
            ? extractText(app.translator.trans(pre + 'discussion', { title: truncate(title) }))
            : extractText(app.translator.trans(pre + 'generic')),
        };
      }

      case 'tag': {
        const tag = current.get('tag');
        const name = tag?.name?.();
        return {
          route: routeName,
          label: name
            ? extractText(app.translator.trans(pre + 'tag', { name: truncate(name) }))
            : extractText(app.translator.trans(pre + 'tags')),
        };
      }

      case 'tags':
        return { route: routeName, label: extractText(app.translator.trans(pre + 'tags')) };

      case 'user': {
        const user = current.get('user');
        const name = user?.displayName?.();
        return {
          route: routeName,
          label: name
            ? extractText(app.translator.trans(pre + 'user', { name: truncate(name) }))
            : extractText(app.translator.trans(pre + 'generic')),
        };
      }

      case 'settings':
        return { route: routeName, label: extractText(app.translator.trans(pre + 'settings')) };

      case 'notifications':
        return { route: routeName, label: extractText(app.translator.trans(pre + 'notifications')) };

      case 'flags':
        return { route: routeName, label: extractText(app.translator.trans(pre + 'flags')) };

      case 'search':
        return { route: routeName, label: extractText(app.translator.trans(pre + 'search')) };

      case 'page': {
        const page = current.get('page');
        const pageTitle = page?.title?.() ?? page?.attribute?.('title');
        return {
          route: routeName,
          label: pageTitle
            ? extractText(app.translator.trans(pre + 'discussion', { title: truncate(pageTitle) }))
            : extractText(app.translator.trans(pre + 'generic')),
        };
      }

      default:
        return { route: routeName, label: extractText(app.translator.trans(pre + 'generic')) };
    }
  } catch (e) {
    return null;
  }
}

function truncate(text: string, max = 60): string {
  if (text.length <= max) return text;
  return text.slice(0, max - 1) + '…';
}

let heartbeatTimer: ReturnType<typeof setInterval> | null = null;
let heartbeatStopped = false;

function intervalMs(): number {
  const seconds = Number(app.forum?.attribute('statserHeartbeatInterval')) || 45;
  return Math.max(15, seconds) * 1000;
}

function fireHeartbeat(): void {
  try {
    if (heartbeatStopped) return;
    if (document.visibilityState !== 'visible') return;
    if (!app.forum || !app.forum.attribute('statserEnableHeartbeat')) return;

    const isAuthenticated = !!(app.session && app.session.user);
    const onIndex = app.current?.get?.('routeName') === 'index';
    const canReport = !!app.forum.attribute('statserCanReportCurrentPage');

    const body: Record<string, string> = {};
    if (canReport) {
      const described = describeCurrentRoute();
      if (described) {
        body.route = described.route;
        body.label = described.label;
      }
    }

    if (!isAuthenticated && !app.forum.attribute('statserShowOnlineGuests')) {
      if (Object.keys(body).length === 0) return;
    }

    app
      .request({
        method: 'POST',
        url: app.forum.attribute('apiUrl') + '/statser/heartbeat',
        body,
        background: true,
        errorHandler: () => {},
      })
      .catch((err: any) => {
        if (err && err.status === 401 && isAuthenticated) {
          heartbeatStopped = true;
          if (heartbeatTimer) {
            clearInterval(heartbeatTimer);
            heartbeatTimer = null;
          }
        }
      });

    if (onIndex && hasVisibleWidgetData()) {
      refreshForumData();
    }
  } catch (e) {}
}

let started = false;

export function startPresence(): void {
  if (started) return;
  started = true;

  heartbeatTimer = setInterval(fireHeartbeat, intervalMs());

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') fireHeartbeat();
  });

  if (!app.session || !app.session.user) {
    setTimeout(fireHeartbeat, 0);
  }

  bindRealtimeRefresh();
}

function bindRealtimeRefresh(): void {
  try {
    const echo = (app as any).pusher;
    if (!echo || typeof echo.channel !== 'function') return;

    const channel = echo.channel('public');
    if (!channel || typeof channel.listenToAll !== 'function') return;

    let debounce: ReturnType<typeof setTimeout> | null = null;

    channel.listenToAll(() => {
      if (app.current?.get?.('routeName') !== 'index') return;
      if (debounce) clearTimeout(debounce);
      debounce = setTimeout(() => refreshForumData(), 800);
    });
  } catch (e) {}
}
