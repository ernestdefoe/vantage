import app from 'flarum/forum/app';

/**
 * What only the browser knows: which posts were actually on screen, and
 * share clicks.
 *
 * 🚨 A post counts once it has been on screen for a second, and the count is
 * sent a couple of seconds later, while the visitor is still on the page.
 * Waiting until they leave lost most views: phones and the in-app browsers of
 * Facebook, Instagram and X often close a page without a last request, and a
 * visitor who reads the first post without scrolling never moved Flarum's
 * read position at all. Members are counted the same way (and still by the
 * read position too); the rollup counts a reader once per post per day.
 */

/** How long a post must be on screen to count, in ms. */
const DWELL = 1000;
/** How long new views wait to be sent together, in ms. */
const BATCH = 2000;
/** The server takes at most this many posts per request. */
const MAX = 60;

let pending: { discussion: number; numbers: Set<number> } | null = null;
let sendTimer: ReturnType<typeof setTimeout> | undefined;
let observer: IntersectionObserver | null = null;
/** Posts counted on this page already (discussion:number), so none is sent twice. */
const counted = new Set<string>();
const timers = new WeakMap<Element, ReturnType<typeof setTimeout>>();
const profilesSent = new Set<string>();

function post(body: Record<string, unknown>, leaving = false) {
  const url = app.forum.attribute<string>('apiUrl') + '/vantage/hit';
  const json = JSON.stringify(body);

  try {
    // A page that is closing only reliably finishes a beacon. The endpoint
    // needs no CSRF header (see extend.php), so it can be used.
    if (leaving && navigator.sendBeacon?.(url, new Blob([json], { type: 'application/json' }))) return;

    const headers: Record<string, string> = { 'Content-Type': 'application/json' };
    if (app.session.csrfToken) headers['X-CSRF-Token'] = app.session.csrfToken;

    fetch(url, { method: 'POST', keepalive: true, credentials: 'same-origin', headers, body: json }).catch(() => {});
  } catch (e) {
    // Counting must never break the page.
  }
}

export function guestCounting(): boolean {
  return !app.session.user && !!app.forum.attribute('vantageGuests');
}

/** Whether this visitor's reads are counted at all. */
function counting(): boolean {
  const user: any = app.session.user;
  if (!user) return !!app.forum.attribute('vantageGuests');

  return !user.preferences?.()?.vantageNoCount;
}

function queue(discussion: number, number: number) {
  const key = discussion + ':' + number;
  if (counted.has(key)) return;
  counted.add(key);

  if (pending && pending.discussion !== discussion) flush();
  if (!pending) pending = { discussion, numbers: new Set() };
  pending.numbers.add(number);

  if (pending.numbers.size >= MAX) flush();
  else if (!sendTimer) sendTimer = setTimeout(() => flush(), BATCH);
}

export function flush(leaving = false) {
  clearTimeout(sendTimer);
  sendTimer = undefined;

  if (pending?.numbers.size) {
    post({ type: 'seen', discussion: pending.discussion, numbers: Array.from(pending.numbers).slice(0, MAX) }, leaving);
  }

  pending = null;
}

/**
 * On screen = half the post shows, or the post fills a third of the window
 * (a long post under the discussion's title never shows half of itself).
 */
function onScreen(entry: IntersectionObserverEntry): boolean {
  return entry.isIntersecting && (entry.intersectionRatio >= 0.5 || entry.intersectionRect.height >= window.innerHeight / 3);
}

function watcher(): IntersectionObserver | null {
  if (observer || typeof IntersectionObserver === 'undefined') return observer;

  observer = new IntersectionObserver(
    (entries) => {
      for (const entry of entries) {
        const el = entry.target as HTMLElement;
        clearTimeout(timers.get(el));

        if (!onScreen(entry)) continue;

        timers.set(
          el,
          setTimeout(() => {
            if (document.visibilityState !== 'visible' || !el.isConnected) return;
            queue(Number(el.dataset.vantageDiscussion), Number(el.dataset.vantageNumber));
            observer?.unobserve(el);
          }, DWELL)
        );
      }
    },
    { threshold: [0, 0.25, 0.5, 0.75, 1] }
  );

  return observer;
}

/** A post in a discussion's stream was drawn: count it once it has been seen. */
export function watch(el: Element | undefined, discussion: number, number: number) {
  if (!el || !discussion || !number || !counting() || counted.has(discussion + ':' + number)) return;
  // Only on that discussion's own page, not a profile's post list. Asked of
  // the page, not the markup: themes rebuild the post stream.
  const current: any = (app.current as any)?.get?.('discussion');
  if (String(current?.id?.()) !== String(discussion)) return;

  const w = watcher();
  if (!w) return;

  (el as HTMLElement).dataset.vantageDiscussion = String(discussion);
  (el as HTMLElement).dataset.vantageNumber = String(number);
  w.observe(el);
}

export function unwatch(el: Element | undefined) {
  if (!el || !observer) return;
  clearTimeout(timers.get(el));
  observer.unobserve(el);
}

export function guestProfile(userId: string) {
  if (!guestCounting() || profilesSent.has(userId)) return;
  profilesSent.add(userId);
  post({ type: 'profile', user: Number(userId) });
}

/** A share button was used: credit the discussion's first post. */
export function share(postId: string | number | undefined) {
  if (!postId) return;
  post({ type: 'share', post: Number(postId) });
}

const SHARE_SELECTORS = [
  // FoF Share Social: the split button, its menu, and the share modal.
  '.item-share-social .SplitDropdown-button',
  '.item-share-social .Dropdown-menu .Button',
  '.FofShareSocialModal .Form-group .Button',
].join(',');

export function listen() {
  window.addEventListener('pagehide', () => flush(true));
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') flush(true);
  });

  document.addEventListener(
    'click',
    (e) => {
      const target = e.target as HTMLElement | null;
      if (!target?.closest?.(SHARE_SELECTORS)) return;

      const discussion: any = (app.current as any)?.get?.('discussion');
      const first = discussion?.data?.relationships?.firstPost?.data?.id;
      share(first);
    },
    true
  );
}
