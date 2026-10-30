import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Select from 'flarum/common/components/Select';
import Dropdown from 'flarum/common/components/Dropdown';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Link from 'flarum/common/components/Link';
import Icon from 'flarum/common/components/Icon';
import abbreviateNumber from 'flarum/common/utils/abbreviateNumber';
import LineChart from './LineChart';

declare const m: import('mithril').Static;
declare const dayjs: any;

const t = (key: string, params?: Record<string, unknown>): any => app.translator.trans('ernestdefoe-vantage.forum.' + key, params as any);

const RANGES = [1, 7, 28, 90, 365, 0];
const ENGAGEMENTS = ['replies', 'likes', 'reactions', 'bookmarks', 'reposts', 'shares'];
const SOURCE_OF: Record<string, string> = {
  likes: 'likes',
  reactions: 'reactions',
  bookmarks: 'bookmarks',
  reposts: 'reposts',
  follows: 'follows',
  unfollows: 'follows',
};

/** Remembered per browser, like any view preference. */
function stored(key: string, fallback: string): string {
  try {
    return window.localStorage.getItem('vantage.' + key) || fallback;
  } catch (e) {
    return fallback;
  }
}

function store(key: string, value: string) {
  try {
    window.localStorage.setItem('vantage.' + key, value);
  } catch (e) {
    // Private windows: the choice just isn't remembered.
  }
}

const number = (n: number) => (n >= 10000 ? abbreviateNumber(n) : n.toLocaleString());

export default class Dashboard extends Component<{ user: any }> {
  range = Number(stored('range', '28'));
  data: any = null;
  locked: string[] | null = null;
  failed = false;
  loading = false;
  shown: string[] = stored('series', 'impressions,engagements').split(',');

  oninit(vnode: any) {
    super.oninit(vnode);
    if (!RANGES.includes(this.range)) this.range = 28;

    // A guest following a link to someone's Stats: nothing to ask the server.
    if (!app.session.user) {
      this.locked = [];
      return;
    }

    this.load();
  }

  tz() {
    return -new Date().getTimezoneOffset();
  }

  url(path = '') {
    return app.forum.attribute<string>('apiUrl') + '/vantage/' + this.attrs.user.id() + path;
  }

  load() {
    this.loading = true;
    this.failed = false;

    app
      .request<any>({
        method: 'GET',
        url: this.url(),
        params: { range: this.range, tz: this.tz() },
        errorHandler: (error: any) => {
          throw error;
        },
      })
      .then((data) => {
        this.data = data;
        this.locked = null;
      })
      .catch((error: any) => {
        const body = error?.response;
        if (error?.status === 403 && body?.errors?.[0]?.code === 'vantage_locked') {
          this.locked = body.groups || [];
        } else {
          this.failed = true;
        }
      })
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }

  setRange(value: string) {
    this.range = Number(value);
    store('range', value);
    this.load();
  }

  view() {
    if (this.locked) return this.lockedView();

    return (
      <div className="Vantage">
        <div className="Vantage-head">
          <h2 className="Vantage-title">{t('page_title', { username: this.attrs.user.displayName() })}</h2>
          <div className="Vantage-controls">
            <Select
              className="Vantage-range"
              aria-label={t('range_label')}
              value={String(this.range)}
              options={Object.fromEntries(RANGES.map((r) => [String(r), t('ranges.' + r)]))}
              onchange={(v: string) => this.setRange(v)}
            />
            <Dropdown className="Vantage-export" buttonClassName="Button" label={t('export')} icon="fas fa-download">
              <a className="Button hasIcon" href={this.url('/export') + `?range=${this.range}&tz=${this.tz()}&format=csv`} download>
                <Icon name="fas fa-file-csv" className="Button-icon" />
                <span className="Button-label">{t('export_csv')}</span>
              </a>
              <a className="Button hasIcon" href={this.url('/export') + `?range=${this.range}&tz=${this.tz()}&format=json`} download>
                <Icon name="fas fa-file-code" className="Button-icon" />
                <span className="Button-label">{t('export_json')}</span>
              </a>
            </Dropdown>
          </div>
        </div>

        {this.failed ? (
          <p className="Vantage-error">{t('error')}</p>
        ) : !this.data ? (
          <LoadingIndicator />
        ) : (
          <div className={'Vantage-body' + (this.loading ? ' is-loading' : '')}>
            {this.data.since && <p className="Vantage-since">{t('since', { date: dayjs(this.data.since).format('LL') })}</p>}
            {this.tiles()}
            {this.chart()}
            {this.bestTimes()}
            {this.types()}
            {this.topPosts()}
            {this.audience()}
          </div>
        )}
      </div>
    );
  }

  lockedView() {
    const groups = this.locked || [];

    return (
      <div className="Vantage Vantage--locked">
        <div className="Vantage-lockedSample" aria-hidden="true">
          {[0, 1, 2, 3].map(() => (
            <div className="Vantage-tile">
              <span className="Vantage-tileValue">—</span>
            </div>
          ))}
        </div>
        <div className="Vantage-lockedText">
          <h2>
            <Icon name="fas fa-chart-line" /> {t('locked.title')}
          </h2>
          <p>{t('locked.text')}</p>
          <p className="Vantage-lockedWho">{groups.length ? t('locked.available_to', { groups: groups.join(', ') }) : t('locked.not_available')}</p>
        </div>
      </div>
    );
  }

  change(now: number | null, before: number | null) {
    if (before === null || now === null || this.range === 0) return null;
    if (before === 0) return now > 0 ? <span className="Vantage-change is-up">{t('new_value')}</span> : null;

    const pct = Math.round(((now - before) / before) * 1000) / 10;
    if (pct === 0) return <span className="Vantage-change">{t('no_change')}</span>;

    return (
      <span className={'Vantage-change ' + (pct > 0 ? 'is-up' : 'is-down')} title={t('vs_previous', { range: t('ranges.' + this.range) })}>
        <Icon name={pct > 0 ? 'fas fa-arrow-up' : 'fas fa-arrow-down'} /> {Math.abs(pct)}%
      </span>
    );
  }

  tiles() {
    const tiles = this.data.tiles;
    const followers = this.data.followers;
    const totals = this.data.totals;

    const tile = (key: string, value: any, change: any, help?: string, detail?: any) => (
      <div className="Vantage-tile" title={help}>
        <span className="Vantage-tileLabel">{t('tiles.' + key)}</span>
        <span className="Vantage-tileValue">{value}</span>
        {change}
        {detail && <span className="Vantage-tileDetail">{detail}</span>}
      </div>
    );

    return (
      <div className="Vantage-tiles">
        {tile('impressions', number(tiles.impressions[0]), this.change(tiles.impressions[0], tiles.impressions[1]), t('tiles.impressions_help'))}
        {tile('engagements', number(tiles.engagements[0]), this.change(tiles.engagements[0], tiles.engagements[1]), t('tiles.engagements_help'))}
        {tile('rate', tiles.rate[0] === null ? '—' : tiles.rate[0] + '%', this.change(tiles.rate[0], tiles.rate[1]), t('tiles.rate_help'))}
        {tile('profile_visits', number(tiles.profileVisits[0]), this.change(tiles.profileVisits[0], tiles.profileVisits[1]))}
        {followers &&
          tile(
            'followers',
            number(followers.total),
            null,
            undefined,
            t('tiles.followers_detail', { verified: number(followers.verified), gained: number(totals.follows), lost: number(totals.unfollows) })
          )}
      </div>
    );
  }

  available(series: string) {
    const source = SOURCE_OF[series];
    return !source || this.data.available[source];
  }

  chart() {
    const options = [
      'impressions',
      'engagements',
      'replies',
      'likes',
      'reactions',
      'bookmarks',
      'reposts',
      'shares',
      'profileVisits',
      'follows',
      'unfollows',
    ].filter((s) => this.available(s));
    const shown = this.shown.filter((s) => options.includes(s));
    const rows = this.data.series.map((row: any) => ({
      ...row,
      engagements: ENGAGEMENTS.reduce((sum, k) => sum + (row[k] || 0), 0),
    }));

    const toggle = (s: string) => {
      this.shown = shown.includes(s) ? shown.filter((x) => x !== s) : [...shown, s];
      store('series', this.shown.join(','));
    };

    return (
      <section className="Vantage-card">
        <h3 className="Vantage-cardTitle">{t('chart.title')}</h3>
        <div className="Vantage-chips" role="group">
          {options.map((s) => (
            <button
              type="button"
              className={'Vantage-chip' + (shown.includes(s) ? ' is-on' : '')}
              aria-pressed={shown.includes(s) ? 'true' : 'false'}
              data-series={options.indexOf(s) % 6}
              onclick={() => toggle(s)}
            >
              <span className="Vantage-chipSwatch" />
              {t('series.' + s)}
            </button>
          ))}
        </div>
        <LineChart
          rows={rows}
          series={shown.map((s) => ({ key: s, label: t('series.' + s), color: options.indexOf(s) % 6 }))}
          granularity={this.data.granularity}
          empty={t('chart.empty')}
        />
      </section>
    );
  }

  bestTimes() {
    const grid: number[][] = this.data.bestTimes;
    const max = Math.max(0, ...grid.flat());
    const days = [0, 1, 2, 3, 4, 5, 6].map((d) => dayjs().day(d).format('ddd'));
    const hourLabel = (h: number) => dayjs().hour(h).minute(0).format('h A');

    let peak: [number, number] | null = null;
    grid.forEach((row, d) =>
      row.forEach((v, h) => {
        if (v > 0 && (!peak || v > grid[peak[0]][peak[1]])) peak = [d, h];
      })
    );

    return (
      <section className="Vantage-card">
        <h3 className="Vantage-cardTitle">{t('best_times.title')}</h3>
        <p className="Vantage-help">{t('best_times.help')}</p>
        {max === 0 ? (
          <p className="Vantage-empty">{t('best_times.empty')}</p>
        ) : (
          [
            <p className="Vantage-peak">
              {t('best_times.peak', { day: dayjs().day(peak![0]).format('dddd'), hour: hourLabel(peak![1]) + '–' + hourLabel((peak![1] + 1) % 24) })}
            </p>,
            <div className="Vantage-heat" role="img" aria-label={t('best_times.title')}>
              {grid.map((row, d) => (
                <div className="Vantage-heatRow">
                  <span className="Vantage-heatDay">{days[d]}</span>
                  {row.map((v, h) => (
                    <span
                      className="Vantage-heatCell"
                      data-level={v === 0 ? 0 : Math.max(1, Math.ceil((v / max) * 4))}
                      title={`${days[d]} ${hourLabel(h)}: ${v}`}
                    />
                  ))}
                </div>
              ))}
              <div className="Vantage-heatRow Vantage-heatHours" aria-hidden="true">
                <span className="Vantage-heatDay" />
                {[0, 6, 12, 18].map((h) => (
                  <span className="Vantage-heatHour" style={{ gridColumn: h + 2 + ' / span 6' }}>
                    {hourLabel(h)}
                  </span>
                ))}
              </div>
            </div>,
          ]
        )}
      </section>
    );
  }

  types() {
    return (
      <section className="Vantage-card">
        <h3 className="Vantage-cardTitle">{t('types.title')}</h3>
        <div className="Vantage-tableWrap">
          <table className="Vantage-table">
            <thead>
              <tr>
                <th>{t('types.type')}</th>
                <th>{t('types.posts')}</th>
                <th>{t('types.impressions')}</th>
                <th>{t('types.engagements')}</th>
                <th>{t('types.rate')}</th>
              </tr>
            </thead>
            <tbody>
              {this.data.types.map((row: any) => (
                <tr>
                  <th scope="row">{t('types.' + row.type)}</th>
                  <td>{number(row.posts)}</td>
                  <td>{number(row.impressions)}</td>
                  <td>{number(row.engagements)}</td>
                  <td>{row.impressions ? Math.round((row.engagements / row.impressions) * 1000) / 10 + '%' : '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <p className="Vantage-help">{t('types.note')}</p>
      </section>
    );
  }

  topPosts() {
    const posts = this.data.topPosts;

    return (
      <section className="Vantage-card">
        <h3 className="Vantage-cardTitle">{t('top.title')}</h3>
        {!posts.length ? (
          <p className="Vantage-empty">{t('top.empty')}</p>
        ) : (
          <ol className="Vantage-top">
            {posts.map((post: any) => (
              <li>
                <Link href={app.route('discussion.near', { id: post.discussionId + (post.slug ? '-' + post.slug : ''), near: post.number })}>
                  <span className="Vantage-topExcerpt">{post.excerpt || post.title}</span>
                  <span className="Vantage-topMeta">
                    {t('top.in', { title: post.title })} · {t('top.views', { count: post.views })}
                    {post.reactions ? [' · ', t('top.reactions', { count: post.reactions })] : null}
                  </span>
                </Link>
              </li>
            ))}
          </ol>
        )}
      </section>
    );
  }

  audience() {
    const a = this.data.audience;
    const total = (list: any[]) => list.reduce((s, x) => s + x.value, 0) || 1;
    const regionNames =
      typeof Intl !== 'undefined' && (Intl as any).DisplayNames
        ? new (Intl as any).DisplayNames([app.translator.getLocale() || 'en'], { type: 'region' })
        : null;

    const label = (group: string, key: string) => {
      if (key === '--') return group === 'countries' ? t('audience.unknown') : t('audience.not_given');
      if (key === 'other') return t('audience.other');
      if (group === 'countries') {
        try {
          return regionNames?.of(key) || key;
        } catch (e) {
          return key;
        }
      }
      if (group === 'genders') return t('audience.genders_list.' + key);
      return key;
    };

    const bars = (group: string, list: any[]) => (
      <div className="Vantage-bars">
        <h4>{t('audience.' + group)}</h4>
        <ul>
          {list.slice(0, 10).map((item) => {
            const pct = Math.round((item.value / total(list)) * 1000) / 10;
            return (
              <li>
                <span className="Vantage-barLabel">{label(group, item.key)}</span>
                <span className="Vantage-barTrack">
                  <span className="Vantage-barFill" style={{ width: pct + '%' }} />
                </span>
                <span className="Vantage-barValue">{pct}%</span>
              </li>
            );
          })}
        </ul>
      </div>
    );

    const demographics = !!app.forum.attribute('vantageDemographics');

    return (
      <section className="Vantage-card">
        <h3 className="Vantage-cardTitle">{t('audience.title')}</h3>
        {a.guestShare !== null && <p className="Vantage-help">{t('audience.guests', { share: a.guestShare })}</p>}
        <div className="Vantage-audience">
          {a.countries.length ? bars('countries', a.countries) : null}
          {demographics && a.enough && a.ages.length ? bars('ages', a.ages) : null}
          {demographics && a.enough && a.genders.length ? bars('genders', a.genders) : null}
        </div>
        {demographics && !a.enough && <p className="Vantage-help">{t('audience.too_few')}</p>}
        {demographics && <p className="Vantage-help">{t('audience.opt_in_note')}</p>}
        {!a.countries.length && !demographics && <p className="Vantage-empty">{t('chart.empty')}</p>}
        {a.countries.length ? (
          <p className="Vantage-credit">
            <a href="https://db-ip.com" rel="noopener" target="_blank">
              {t('audience.dbip')}
            </a>
          </p>
        ) : null}
      </section>
    );
  }
}
