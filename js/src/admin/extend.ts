import app from 'flarum/admin/app';
import Extend from 'flarum/common/extenders';
import StatserSettingsPage from './components/StatserSettingsPage';

const PERM_PRE = 'forumaker-statser.admin.permissions.';

export default [
  new Extend.Admin()
    .page(StatserSettingsPage)
    .permission(
      () => ({
        icon: 'fas fa-users',
        label: app.translator.trans(PERM_PRE + 'view_online_users'),
        permission: 'forumaker-statser.viewOnlineUsers',
        allowGuest: true,
      }),
      'view'
    )
    .permission(
      () => ({
        icon: 'fas fa-comments',
        label: app.translator.trans(PERM_PRE + 'view_discussions_count'),
        permission: 'forumaker-statser.viewStats.discussionsCount',
        allowGuest: true,
      }),
      'view'
    )
    .permission(
      () => ({
        icon: 'fas fa-comment-dots',
        label: app.translator.trans(PERM_PRE + 'view_posts_count'),
        permission: 'forumaker-statser.viewStats.postsCount',
        allowGuest: true,
      }),
      'view'
    )
    .permission(
      () => ({
        icon: 'fas fa-user-friends',
        label: app.translator.trans(PERM_PRE + 'view_users_count'),
        permission: 'forumaker-statser.viewStats.usersCount',
        allowGuest: true,
      }),
      'view'
    )
    .permission(
      () => ({
        icon: 'fas fa-user-plus',
        label: app.translator.trans(PERM_PRE + 'view_latest_registration'),
        permission: 'forumaker-statser.viewStats.latestMember',
        allowGuest: true,
      }),
      'view'
    )
    .permission(
      () => ({
        icon: 'fas fa-eye',
        label: app.translator.trans(PERM_PRE + 'view_current_page'),
        permission: 'forumaker-statser.viewCurrentPage',
        allowGuest: false,
      }),
      'view'
    ),
];
