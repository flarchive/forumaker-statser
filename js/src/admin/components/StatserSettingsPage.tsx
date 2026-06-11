import app from 'flarum/admin/app';
import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import Switch from 'flarum/common/components/Switch';

const PRE = 'forumaker-statser.admin.settings.';

function isTrue(v: unknown): boolean {
  return v === true || v === 1 || v === '1' || v === 'true';
}

export default class StatserSettingsPage extends ExtensionPage {
  className() {
    return 'StatserSettingsPage';
  }

  content() {
    const showOnline = isTrue(this.setting('forumaker-statser.show_online_users')());
    const showGuests = isTrue(this.setting('forumaker-statser.show_online_guests')());
    const heartbeatEnabled = isTrue(this.setting('forumaker-statser.enable_heartbeat')());

    return (
      <div className="StatserSettingsPage">
        <div className="StatserSettingsPage-content">

          <section className="Statser-SettingsSection">
            <h3>
              <i className="fas fa-users" />
              {app.translator.trans(PRE + 'section_online')}
            </h3>
            <div className="Statser-SettingsSection-content">

              <div className="Form-group">
                <Switch
                  state={showOnline}
                  onchange={(v: boolean) => this.setting('forumaker-statser.show_online_users')(v ? '1' : '0')}
                >
                  {app.translator.trans(PRE + 'show_online_users')}
                </Switch>
                <p className="helpText">{app.translator.trans(PRE + 'show_online_users_help')}</p>
              </div>

              <div className={'Form-group' + (!showOnline ? ' Statser-disabled' : '')}>
                <Switch
                  state={showGuests}
                  onchange={(v: boolean) => this.setting('forumaker-statser.show_online_guests')(v ? '1' : '0')}
                  disabled={!showOnline}
                >
                  {app.translator.trans(PRE + 'show_online_guests')}
                </Switch>
                <p className="helpText">{app.translator.trans(PRE + 'show_online_guests_help')}</p>
              </div>

              <div className={'Form-group' + (!showOnline || !showGuests ? ' Statser-disabled' : '')}>
                <Switch
                  state={isTrue(this.setting('forumaker-statser.exclude_bots')())}
                  onchange={(v: boolean) => this.setting('forumaker-statser.exclude_bots')(v ? '1' : '0')}
                  disabled={!showOnline || !showGuests}
                >
                  {app.translator.trans(PRE + 'exclude_bots')}
                </Switch>
                <p className="helpText">{app.translator.trans(PRE + 'exclude_bots_help')}</p>
              </div>

              <div className={'Form-group' + (!showOnline ? ' Statser-disabled' : '')}>
                <Switch
                  state={isTrue(this.setting('forumaker-statser.show_hidden_users')())}
                  onchange={(v: boolean) => this.setting('forumaker-statser.show_hidden_users')(v ? '1' : '0')}
                  disabled={!showOnline}
                >
                  {app.translator.trans(PRE + 'show_hidden_users')}
                </Switch>
                <p className="helpText">{app.translator.trans(PRE + 'show_hidden_users_help')}</p>
              </div>

              <div className={'Form-group' + (!showOnline || !showGuests ? ' Statser-disabled' : '')}>
                <Switch
                  state={isTrue(this.setting('forumaker-statser.include_guests_in_total')())}
                  onchange={(v: boolean) => this.setting('forumaker-statser.include_guests_in_total')(v ? '1' : '0')}
                  disabled={!showOnline || !showGuests}
                >
                  {app.translator.trans(PRE + 'include_guests_in_total')}
                </Switch>
              </div>

              <div className="Form-group">
                <label>{app.translator.trans(PRE + 'max_avatars')}</label>
                <p className="helpText">{app.translator.trans(PRE + 'max_avatars_help')}</p>
                <input
                  className="FormControl"
                  type="number"
                  min={1}
                  bidi={this.setting('forumaker-statser.max_avatars')}
                />
              </div>

            </div>
          </section>

          <section className="Statser-SettingsSection">
            <h3>
              <i className="fas fa-chart-bar" />
              {app.translator.trans(PRE + 'section_stats')}
            </h3>
            <div className="Statser-SettingsSection-content">

              <div className="Form-group">
                <Switch
                  state={isTrue(this.setting('forumaker-statser.show_discussions_count')())}
                  onchange={(v: boolean) => this.setting('forumaker-statser.show_discussions_count')(v ? '1' : '0')}
                >
                  {app.translator.trans(PRE + 'show_discussions_count')}
                </Switch>
              </div>

              <div className="Form-group">
                <Switch
                  state={isTrue(this.setting('forumaker-statser.show_posts_count')())}
                  onchange={(v: boolean) => this.setting('forumaker-statser.show_posts_count')(v ? '1' : '0')}
                >
                  {app.translator.trans(PRE + 'show_posts_count')}
                </Switch>
              </div>

              <div className="Form-group">
                <Switch
                  state={isTrue(this.setting('forumaker-statser.show_users_count')())}
                  onchange={(v: boolean) => this.setting('forumaker-statser.show_users_count')(v ? '1' : '0')}
                >
                  {app.translator.trans(PRE + 'show_users_count')}
                </Switch>
              </div>

              <div className="Form-group">
                <Switch
                  state={isTrue(this.setting('forumaker-statser.show_latest_registration')())}
                  onchange={(v: boolean) => this.setting('forumaker-statser.show_latest_registration')(v ? '1' : '0')}
                >
                  {app.translator.trans(PRE + 'show_latest_registration')}
                </Switch>
              </div>

              <div className="Form-group">
                <Switch
                  state={isTrue(this.setting('forumaker-statser.ignore_private_discussions')())}
                  onchange={(v: boolean) => this.setting('forumaker-statser.ignore_private_discussions')(v ? '1' : '0')}
                >
                  {app.translator.trans(PRE + 'ignore_private_discussions')}
                </Switch>
              </div>

              <div className="Form-group">
                <label>{app.translator.trans(PRE + 'stats_cache_duration')}</label>
                <p className="helpText">{app.translator.trans(PRE + 'stats_cache_duration_help')}</p>
                <input
                  className="FormControl"
                  type="number"
                  min={0}
                  bidi={this.setting('forumaker-statser.stats_cache_duration')}
                />
              </div>

            </div>
          </section>

          <section className="Statser-SettingsSection">
            <h3>
              <i className="fas fa-clock" />
              {app.translator.trans(PRE + 'section_activity')}
            </h3>
            <div className="Statser-SettingsSection-content">

              <div className="Form-group">
                <Switch
                  state={heartbeatEnabled}
                  onchange={(v: boolean) => this.setting('forumaker-statser.enable_heartbeat')(v ? '1' : '0')}
                >
                  {app.translator.trans(PRE + 'enable_heartbeat')}
                </Switch>
                <p className="helpText">{app.translator.trans(PRE + 'enable_heartbeat_help')}</p>
              </div>

              <div className={'Form-group' + (!heartbeatEnabled ? ' Statser-disabled' : '')}>
                <label>{app.translator.trans(PRE + 'heartbeat_interval')}</label>
                <p className="helpText">{app.translator.trans(PRE + 'heartbeat_interval_help')}</p>
                <input
                  className="FormControl"
                  type="number"
                  min={15}
                  disabled={!heartbeatEnabled}
                  bidi={this.setting('forumaker-statser.heartbeat_interval')}
                />
              </div>

              <div className="Form-group">
                <label>{app.translator.trans(PRE + 'last_seen_interval')}</label>
                <p className="helpText">{app.translator.trans(PRE + 'last_seen_interval_help')}</p>
                <input
                  className="FormControl"
                  type="number"
                  min={1}
                  bidi={this.setting('forumaker-statser.last_seen_interval')}
                />
              </div>

              <div className="Form-group">
                <Switch
                  state={isTrue(this.setting('forumaker-statser.show_current_page')())}
                  onchange={(v: boolean) => this.setting('forumaker-statser.show_current_page')(v ? '1' : '0')}
                >
                  {app.translator.trans(PRE + 'show_current_page')}
                </Switch>
                <p className="helpText">{app.translator.trans(PRE + 'show_current_page_help')}</p>
              </div>

              <div className="Form-group">
                <label>{app.translator.trans(PRE + 'online_users_cache_ttl')}</label>
                <input
                  className="FormControl"
                  type="number"
                  min={0}
                  bidi={this.setting('forumaker-statser.online_users_cache_ttl')}
                />
              </div>

            </div>
          </section>

          {this.submitButton()}
        </div>
      </div>
    );
  }
}
