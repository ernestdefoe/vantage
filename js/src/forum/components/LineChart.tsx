import Component from 'flarum/common/Component';

declare const m: import('mithril').Static;
declare const dayjs: any;

type Series = { key: string; label: string; color: number };

interface Attrs {
  rows: Array<Record<string, any>>;
  series: Series[];
  granularity: 'day' | 'month';
  empty: string;
}

const W = 600;
const H = 200;

/**
 * Hand-drawn SVG, so the dashboard brings no charting library. The plot
 * stretches to its card (lines keep their width with non-scaling strokes),
 * and every label is HTML, so it stays readable at any width.
 */
export default class LineChart extends Component<Attrs> {
  hover: number | null = null;

  label(key: string, long = false) {
    const d = dayjs(key.length === 7 ? key + '-01' : key);
    if (this.attrs.granularity === 'month') return d.format(long ? 'MMMM YYYY' : 'MMM YYYY');
    return d.format(long ? 'ddd, LL' : 'MMM D');
  }

  view() {
    const { rows, series } = this.attrs;
    const n = rows.length;
    const max = Math.max(1, ...rows.flatMap((r) => series.map((s) => r[s.key] || 0)));
    const nice = niceMax(max);
    const x = (i: number) => (n <= 1 ? W / 2 : (i / (n - 1)) * W);
    const y = (v: number) => H - (v / nice) * H;
    const empty = !series.length || rows.every((r) => series.every((s) => !r[s.key]));

    const move = (e: PointerEvent) => {
      const box = (e.currentTarget as HTMLElement).getBoundingClientRect();
      const ratio = Math.min(1, Math.max(0, (e.clientX - box.left) / box.width));
      this.hover = n <= 1 ? 0 : Math.round(ratio * (n - 1));
    };

    const at = this.hover !== null ? rows[this.hover] : null;
    const left = this.hover !== null ? (x(this.hover) / W) * 100 : 0;

    return (
      <div className="Vantage-chart">
        <div className="Vantage-chartAxis" aria-hidden="true">
          <span>{nice.toLocaleString()}</span>
          <span>{Math.round(nice / 2).toLocaleString()}</span>
          <span>0</span>
        </div>
        <div
          className="Vantage-chartPlot"
          onpointermove={move}
          onpointerdown={move}
          onpointerleave={(e: PointerEvent) => {
            // A tap is a pointerdown + pointerleave on touch: keep the tooltip.
            if (e.pointerType === 'mouse') this.hover = null;
          }}
        >
          <svg viewBox={`0 0 ${W} ${H}`} preserveAspectRatio="none" role="img" aria-label={series.map((s) => s.label).join(', ')}>
            {[0, 0.5, 1].map((f) => (
              <line className="Vantage-chartGrid" x1="0" x2={W} y1={H * f} y2={H * f} vector-effect="non-scaling-stroke" />
            ))}
            {!empty &&
              series.map((s) => (
                <polyline
                  className="Vantage-chartLine"
                  data-series={s.color}
                  vector-effect="non-scaling-stroke"
                  points={rows.map((r, i) => `${x(i).toFixed(1)},${y(r[s.key] || 0).toFixed(1)}`).join(' ')}
                />
              ))}
            {at && <line className="Vantage-chartCursor" x1={x(this.hover!)} x2={x(this.hover!)} y1="0" y2={H} vector-effect="non-scaling-stroke" />}
          </svg>
          {empty && <p className="Vantage-chartEmpty">{this.attrs.empty}</p>}
          {at && !empty && (
            <div className={'Vantage-tip' + (left > 60 ? ' is-left' : '')} style={{ left: left + '%' }}>
              <strong>{this.label(at.key, true)}</strong>
              {series.map((s) => (
                <span className="Vantage-tipRow" data-series={s.color}>
                  <span className="Vantage-chipSwatch" />
                  {s.label}: {(at[s.key] || 0).toLocaleString()}
                </span>
              ))}
            </div>
          )}
        </div>
        {n > 0 && (
          <div className="Vantage-chartDates" aria-hidden="true">
            <span>{this.label(rows[0].key)}</span>
            {n > 2 && <span>{this.label(rows[Math.floor((n - 1) / 2)].key)}</span>}
            {n > 1 && <span>{this.label(rows[n - 1].key)}</span>}
          </div>
        )}
      </div>
    );
  }
}

/** 1, 2 or 5 times a power of ten, so the axis reads 0 / 50 / 100. */
function niceMax(max: number): number {
  const p = Math.pow(10, Math.floor(Math.log10(max)));
  for (const m of [1, 2, 5, 10]) if (max <= m * p) return m * p;
  return 10 * p;
}
