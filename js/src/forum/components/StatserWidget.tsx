import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Avatar from 'flarum/common/components/Avatar';
import Link from 'flarum/common/components/Link';
import Tooltip from 'flarum/common/components/Tooltip';
import username from 'flarum/common/helpers/username';
import formatNumber from 'flarum/common/utils/formatNumber';
import extractText from 'flarum/common/utils/extractText';

const PRE = 'forumaker-statser.forum.widget.';

type ActivityEntry = { route?: string | null; label?: string | null; standalone?: boolean | null };

function parseActivityMap(raw: unknown): Record<string, ActivityEntry> {
  if (typeof raw !== 'string' || !raw) return {};
  try {
    const parsed = JSON.parse(raw);
    return parsed && typeof parsed === 'object' ? parsed : {};
  } catch (e) {
    return {};
  }
}

interface StatserAttrs {
  waitForDiscussions?: boolean;
}

export default class StatserWidget extends Component<StatserAttrs> {
  view() {
    if (this.attrs.waitForDiscussions && !document.querySelector('.DiscussionList li')) {
      return <div />;
    }

    const forum = app.forum;
    if (!forum) return <div />;

    const canViewOnline = !!forum.attribute('statserCanViewOnline');
    const onlineUsers = canViewOnline ? (forum as any).statserOnlineUsers?.() || [] : [];
    const totalOnline = canViewOnline ? Number(forum.attribute('statserTotalOnline')) || 0 : 0;
    const hiddenOnline = canViewOnline ? Number(forum.attribute('statserHiddenOnline')) || 0 : 0;

    const showGuests = canViewOnline && !!forum.attribute('statserShowOnlineGuests');
    const includeGuestsInTotal = showGuests && !!forum.attribute('statserIncludeGuestsInTotal');
    const onlineGuests = showGuests ? Number(forum.attribute('statserOnlineGuests')) || 0 : 0;
    const displayedOnline = includeGuestsInTotal ? totalOnline + onlineGuests : totalOnline;

    const showCurrentPage = canViewOnline && !!forum.attribute('statserShowCurrentPage');
    const activityMap = showCurrentPage ? parseActivityMap(forum.attribute('statserActivityMap')) : {};

    const discussionsCount = forum.attribute<number | null>('statserDiscussionsCount');
    const postsCount = forum.attribute<number | null>('statserPostsCount');
    const usersCount = forum.attribute<number | null>('statserUsersCount');

    let latestUser: any = null;
    try {
      latestUser = (forum as any).statserLatestUser?.();
    } catch (e) {}

    const hasOnlineRow = canViewOnline;
    const hasStatsRow = discussionsCount != null || postsCount != null || usersCount != null || !!latestUser;

    if (!hasOnlineRow && !hasStatsRow) {
      return <div />;
    }

    const maxAvatars = Math.max(1, Number(forum.attribute('statserMaxAvatars')) || 24);
    const visibleUsers = onlineUsers.slice(0, maxAvatars);
    const overflow = Math.max(0, totalOnline - onlineUsers.length - hiddenOnline);
    const extraOverflow = Math.max(0, onlineUsers.length - visibleUsers.length);
    const totalOverflow = overflow + extraOverflow;

    const renderUserAvatar = (user: any) => {
      const entry = activityMap[String(user.id())];
      const tooltipParts: string[] = [extractText(username(user))];

      if (showCurrentPage) {
        tooltipParts.push(
          entry?.label
            ? entry.standalone
              ? entry.label
              : extractText(app.translator.trans(PRE + 'viewing_page', { page: entry.label }))
            : extractText(app.translator.trans(PRE + 'viewing_unknown'))
        );
      }

      return (
        <Tooltip text={tooltipParts.join(' · ')} key={user.id()}>
          <Link href={app.route('user', { username: user.slug() })} className="StatserWidget-avatar">
            <span aria-hidden="true">
              <Avatar user={user} />
            </span>
          </Link>
        </Tooltip>
      );
    };

    const onlineRow = hasOnlineRow ? (
      <div className="StatserWidget-onlineRow">
        <div className="StatserWidget-onlineHeader">
          <span className="StatserWidget-onlineDot" aria-hidden="true" />
          <span className="StatserWidget-onlineTitle">{app.translator.trans(PRE + 'online_label')}</span>
          {displayedOnline > 0 && <span className="StatserWidget-onlineCount">{formatNumber(displayedOnline)}</span>}
        </div>

        {visibleUsers.length > 0 || totalOverflow > 0 || hiddenOnline > 0 || onlineGuests > 0 ? (
          <div className="StatserWidget-avatars">
            {visibleUsers.map(renderUserAvatar)}

            {totalOverflow > 0 ? (
              <Tooltip text={extractText(app.translator.trans(PRE + 'online_users_count', { count: totalOverflow }))}>
                <span className="StatserWidget-badge StatserWidget-badge--overflow">+{formatNumber(totalOverflow)}</span>
              </Tooltip>
            ) : null}

            {hiddenOnline > 0 ? (
              <Tooltip text={extractText(app.translator.trans(PRE + 'hidden_users_count', { count: hiddenOnline }))}>
                <span className="StatserWidget-badge StatserWidget-badge--hidden">{formatNumber(hiddenOnline)}</span>
              </Tooltip>
            ) : null}

            {onlineGuests > 0 ? (
              <Tooltip text={extractText(app.translator.trans(PRE + 'online_guests_count', { count: onlineGuests }))}>
                <span className="StatserWidget-badge StatserWidget-badge--guest">{formatNumber(onlineGuests)}</span>
              </Tooltip>
            ) : null}
          </div>
        ) : (
          <span className="StatserWidget-empty">{app.translator.trans(PRE + 'empty_online')}</span>
        )}
      </div>
    ) : null;

    const statChips: Mithril.Children[] = [];

    if (usersCount != null) {
      statChips.push(
        <div className="StatserWidget-stat" key="users">
          <i className="StatserWidget-statIcon icon fas fa-users" aria-hidden="true" />
          <span className="StatserWidget-statValue">{formatNumber(usersCount)}</span>
          <span className="StatserWidget-statLabel">{app.translator.trans(PRE + 'total_users', { count: usersCount })}</span>
        </div>
      );
    }
    if (discussionsCount != null) {
      statChips.push(
        <div className="StatserWidget-stat" key="discussions">
          <i className="StatserWidget-statIcon icon fas fa-comments" aria-hidden="true" />
          <span className="StatserWidget-statValue">{formatNumber(discussionsCount)}</span>
          <span className="StatserWidget-statLabel">{app.translator.trans(PRE + 'discussions', { count: discussionsCount })}</span>
        </div>
      );
    }
    if (postsCount != null) {
      statChips.push(
        <div className="StatserWidget-stat" key="posts">
          <i className="StatserWidget-statIcon icon fas fa-comment-dots" aria-hidden="true" />
          <span className="StatserWidget-statValue">{formatNumber(postsCount)}</span>
          <span className="StatserWidget-statLabel">{app.translator.trans(PRE + 'posts', { count: postsCount })}</span>
        </div>
      );
    }
    if (latestUser) {
      statChips.push(
        <div className="StatserWidget-stat StatserWidget-stat--latest" key="latest">
          <i className="StatserWidget-statIcon icon fas fa-user-plus" aria-hidden="true" />
          <Link
            href={app.route('user', { username: latestUser.slug() })}
            className="StatserWidget-latestUser"
            aria-label={extractText(username(latestUser))}
          >
            <span aria-hidden="true">
              <Avatar user={latestUser} />
            </span>
            <span className="StatserWidget-latestUsername" aria-hidden="true">{username(latestUser)}</span>
          </Link>
        </div>
      );
    }

    const statsRow = hasStatsRow && statChips.length > 0 ? (
      <div className="StatserWidget-statsRow">{statChips}</div>
    ) : null;

    const hasBothRows = hasOnlineRow && statsRow;

    return (
      <section className="StatserWidget" aria-label={extractText(app.translator.trans(PRE + 'title'))}>
        <div className={'StatserWidget-panel' + (hasBothRows ? ' StatserWidget-panel--divided' : '')}>
          {onlineRow}
          {statsRow}
        </div>
      </section>
    );
  }
}
