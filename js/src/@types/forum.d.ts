import 'flarum/common/models/Forum';

// The attributes and relationships the forum payload gets from this extension (see forum/index.tsx, where they are
// registered on Forum.prototype). Typed loosely: each is read through Model.attribute()/hasMany()/hasOne().
declare module 'flarum/common/models/Forum' {
  export default interface Forum {
    statserCanViewOnline: () => any;
    statserTotalOnline: () => any;
    statserHiddenOnline: () => any;
    statserOnlineUsers: () => any;
    statserActivityMap: () => any;
    statserShowOnlineGuests: () => any;
    statserIncludeGuestsInTotal: () => any;
    statserOnlineGuests: () => any;
    statserDiscussionsCount: () => any;
    statserPostsCount: () => any;
    statserUsersCount: () => any;
    statserLatestUser: () => any;
    statserEnableHeartbeat: () => any;
    statserHeartbeatInterval: () => any;
    statserShowCurrentPage: () => any;
    statserCanReportCurrentPage: () => any;
    statserMaxAvatars: () => any;
  }
}
