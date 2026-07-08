import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import Forum from 'flarum/common/models/Forum';
import Model from 'flarum/common/Model';
import IndexPage from 'flarum/forum/components/IndexPage';
import TagsPage from 'ext:flarum/tags/forum/components/TagsPage';

import StatserWidget from './components/StatserWidget';
import { refreshForumData, startPresence } from './utils/presence';

app.initializers.add('forumaker-statser', () => {
  Forum.prototype.statserCanViewOnline = Model.attribute('statserCanViewOnline');
  Forum.prototype.statserTotalOnline = Model.attribute('statserTotalOnline');
  Forum.prototype.statserHiddenOnline = Model.attribute('statserHiddenOnline');
  Forum.prototype.statserOnlineUsers = Model.hasMany('statserOnlineUsers');
  Forum.prototype.statserActivityMap = Model.attribute('statserActivityMap');

  Forum.prototype.statserShowOnlineGuests = Model.attribute('statserShowOnlineGuests');
  Forum.prototype.statserIncludeGuestsInTotal = Model.attribute('statserIncludeGuestsInTotal');
  Forum.prototype.statserOnlineGuests = Model.attribute('statserOnlineGuests');

  Forum.prototype.statserDiscussionsCount = Model.attribute('statserDiscussionsCount');
  Forum.prototype.statserPostsCount = Model.attribute('statserPostsCount');
  Forum.prototype.statserUsersCount = Model.attribute('statserUsersCount');
  Forum.prototype.statserLatestUser = Model.hasOne('statserLatestUser');

  Forum.prototype.statserEnableHeartbeat = Model.attribute('statserEnableHeartbeat');
  Forum.prototype.statserHeartbeatInterval = Model.attribute('statserHeartbeatInterval');
  Forum.prototype.statserShowCurrentPage = Model.attribute('statserShowCurrentPage');
  Forum.prototype.statserCanReportCurrentPage = Model.attribute('statserCanReportCurrentPage');
  Forum.prototype.statserMaxAvatars = Model.attribute('statserMaxAvatars');

  extend(IndexPage.prototype, 'oninit', function () {
    if (app.previous?.type) refreshForumData();
  });

  extend(IndexPage.prototype, 'contentItems', function (items) {
    items.add('statserWidget', <StatserWidget waitForDiscussions />, -10000);
  });

  if (TagsPage) {
    extend(TagsPage.prototype, 'view', function (this: any, output: any) {
      if (!output || !Array.isArray(output.children)) return;
      output.children.push(<StatserWidget />);
    });

    extend(TagsPage.prototype, 'oninit', function (this: any) {
      if (app.previous?.type) refreshForumData();
    });
  }

  startPresence();
}, -1);
