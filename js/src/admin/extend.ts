import Extend from 'flarum/common/extenders';
import StatserSettingsPage from './components/StatserSettingsPage';

export default [
  new Extend.Admin().page(StatserSettingsPage),
];
