import app from 'flarum/admin/app';

const EXTENSION_ID = 'forumaker-statser';
const PERM_PRE = 'forumaker-statser.admin.permissions.';

app.initializers.add(EXTENSION_ID, () => {
  const reg = (app as any).registry.for(EXTENSION_ID);

  reg.registerPermission(
    {
      icon: 'fas fa-users',
      label: app.translator.trans(PERM_PRE + 'view_online_users'),
      permission: 'forumaker-statser.viewOnlineUsers',
      allowGuest: true,
    },
    'view'
  );
  reg.registerPermission(
    {
      icon: 'fas fa-comments',
      label: app.translator.trans(PERM_PRE + 'view_discussions_count'),
      permission: 'forumaker-statser.viewStats.discussionsCount',
      allowGuest: true,
    },
    'view'
  );
  reg.registerPermission(
    {
      icon: 'fas fa-comment-dots',
      label: app.translator.trans(PERM_PRE + 'view_posts_count'),
      permission: 'forumaker-statser.viewStats.postsCount',
      allowGuest: true,
    },
    'view'
  );
  reg.registerPermission(
    {
      icon: 'fas fa-user-friends',
      label: app.translator.trans(PERM_PRE + 'view_users_count'),
      permission: 'forumaker-statser.viewStats.usersCount',
      allowGuest: true,
    },
    'view'
  );
  reg.registerPermission(
    {
      icon: 'fas fa-user-plus',
      label: app.translator.trans(PERM_PRE + 'view_latest_registration'),
      permission: 'forumaker-statser.viewStats.latestMember',
      allowGuest: true,
    },
    'view'
  );
  reg.registerPermission(
    {
      icon: 'fas fa-eye',
      label: app.translator.trans(PERM_PRE + 'view_current_page'),
      permission: 'forumaker-statser.viewCurrentPage',
      allowGuest: false,
    },
    'view'
  );
});
