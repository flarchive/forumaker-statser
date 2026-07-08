// Permissions used to be registered here via the undocumented `app.registry`
// API. They now live in `./extend.ts`, registered through the documented
// `Extend.Admin().permission()` extender (see js/admin.ts).
//
// This file is kept as an empty module — rather than deleted — because
// workspace files can't be removed once written; `js/admin.ts` no longer
// imports it.
