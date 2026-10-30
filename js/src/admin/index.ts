import app from 'flarum/admin/app';

const t = (key: string) => app.translator.trans('ernestdefoe-vantage.admin.' + key);
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
