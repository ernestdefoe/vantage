import app from 'flarum/admin/app';
import Button from 'flarum/common/components/Button';

declare const m: import('mithril').Static;

const t = (key: string, params?: Record<string, unknown>) => app.translator.trans('ernestdefoe-vantage.admin.' + key, params as any);
const S = 'ernestdefoe-vantage.';

app.initializers.add('ernestdefoe/vantage', () => {
  app.registry
    .for('ernestdefoe-vantage')
    .registerSetting({ setting: S + 'count_guests', type: 'boolean', label: t('settings.count_guests_label'), help: t('settings.count_guests_help') })
    .registerSetting({
      setting: S + 'post_views',
      type: 'select',
      label: t('settings.post_views_label'),
      help: t('settings.post_views_help'),
      options: { everyone: t('settings.post_views_everyone'), author: t('settings.post_views_author'), off: t('settings.post_views_off') },
      default: 'everyone',
    })
    .registerSetting({ setting: S + 'demographics', type: 'boolean', label: t('settings.demographics_label'), help: t('settings.demographics_help') })
    .registerSetting({
      setting: S + 'country_source',
      type: 'select',
      label: t('settings.country_source_label'),
      help: t('settings.country_source_help'),
      options: {
        auto: t('settings.country_auto'),
        header: t('settings.country_header'),
        dbip: t('settings.country_dbip'),
        off: t('settings.country_off'),
      },
      default: 'auto',
    })
    .registerSetting({
      setting: S + 'country_header',
      type: 'text',
      label: t('settings.country_header_label'),
      help: t('settings.country_header_help'),
    })
    .registerSetting(() => importSetting(), -10)
    .registerSetting({
      setting: S + 'retention_months',
      type: 'number',
      min: 3,
      max: 120,
      label: t('settings.retention_label'),
      help: t('settings.retention_help'),
    })
    .registerPermission(
      { icon: 'far fa-eye', label: t('permissions.view_post_stats'), permission: 'vantage.viewPostStats', allowGuest: true },
      'view'
    )
    .registerPermission({ icon: 'fas fa-chart-line', label: t('permissions.view_dashboard'), permission: 'vantage.viewDashboard' }, 'start')
    .registerPermission({ icon: 'fas fa-chart-line', label: t('permissions.view_others'), permission: 'vantage.viewOthers' }, 'moderate');
});

/** The one-time FoF Discussion Views import: a button, shown only when there's something to import. */
const importState: { loading: boolean; result: any } = { loading: false, result: null };

function importSetting() {
  if (!app.forum.attribute('vantageDiscussionViews')) return null;

  const run = () => {
    importState.loading = true;
    app
      .request<{ members: number; views: number }>({ method: 'POST', url: app.forum.attribute('apiUrl') + '/vantage/import-discussion-views' })
      .then((r) => {
        importState.result = t('settings.import_done', { views: r.views.toLocaleString(), count: r.members });
      })
      .catch(() => {})
      .finally(() => {
        importState.loading = false;
        m.redraw();
      });
  };

  return m('.Form-group.VantageImport', [
    m('label', t('settings.import_label')),
    m('.helpText', t('settings.import_help')),
    m(Button, { className: 'Button', loading: importState.loading, onclick: run }, t('settings.import_button')),
    importState.result ? m('p.helpText', importState.result) : null,
  ]);
}
