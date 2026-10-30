import app from 'flarum/forum/app';
import FieldSet from 'flarum/common/components/FieldSet';
import Switch from 'flarum/common/components/Switch';
import Select from 'flarum/common/components/Select';
import ItemList from 'flarum/common/utils/ItemList';

declare const m: import('mithril').Static;

const t = (key: string): any => app.translator.trans('ernestdefoe-vantage.forum.settings.' + key);

/** Settings → "Stats and privacy": the reader's own choices. */
export function settingsItems(items: ItemList<any>) {
  const user = app.session.user;
  if (!user) return;

  const prefs = user.preferences() || {};
  const save = (key: string, value: unknown) => user.savePreferences({ [key]: value }).then(() => m.redraw());
  const demographics = !!app.forum.attribute('vantageDemographics');

  const years: Record<string, string> = { '': t('gender_none') };
  const now = new Date().getFullYear();
  for (let y = now - 13; y >= now - 100; y--) years[String(y)] = String(y);

  items.add(
    'vantage',
    <FieldSet className="Settings-vantage" label={t('title')}>
      <Switch state={!!prefs.vantageNoCount} onchange={(v: boolean) => save('vantageNoCount', v)}>
        {t('no_count')}
      </Switch>
      {demographics && [
        <Switch state={!!prefs.vantageShareAudience} onchange={(v: boolean) => save('vantageShareAudience', v)}>
          {t('share_audience')}
        </Switch>,
        prefs.vantageShareAudience && (
          <div className="Settings-vantageFields">
            <label>
              <span>{t('birth_year')}</span>
              <Select
                value={prefs.vantageBirthYear ? String(prefs.vantageBirthYear) : ''}
                options={years}
                onchange={(v: string) => save('vantageBirthYear', v ? Number(v) : null)}
              />
            </label>
            <label>
              <span>{t('gender')}</span>
              <Select
                value={prefs.vantageGender || ''}
                options={{
                  '': t('gender_none'),
                  woman: app.translator.trans('ernestdefoe-vantage.forum.audience.genders_list.woman'),
                  man: app.translator.trans('ernestdefoe-vantage.forum.audience.genders_list.man'),
                  nonbinary: app.translator.trans('ernestdefoe-vantage.forum.audience.genders_list.nonbinary'),
                }}
                onchange={(v: string) => save('vantageGender', v || null)}
              />
            </label>
          </div>
        ),
      ]}
    </FieldSet>,
    -6
  );
}
