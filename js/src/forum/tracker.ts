import app from 'flarum/forum/app';

/**
 * What only the browser knows: a guest's reads and anyone's share clicks.
 *
 * Members' reads are NOT sent from here; the forum already saves how far a
 * member has read, and the server counts that. A guest's reads are collected
 * as they scroll and sent once, when they leave the discussion or the tab.
 */

type Pending = { discussion: number; numbers: Set<number> };

let pending: Pending | null = null;
const profilesSent = new Set<string>();

function post(body: Record<string, unknown>) {
  try {
    // keepalive lets the request finish while the page is closing.
    fetch(app.forum.attribute<string>('apiUrl') + '/vantage/hit', {
      method: 'POST',
      keepalive: true,
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': app.session.csrfToken || '' },
      body: JSON.stringify(body),
    }).catch(() => {});
  } catch (e) {
    // Counting must never break the page.
  }
}

export function guestCounting(): boolean {
  return !app.session.user && !!app.forum.attribute('vantageGuests');
}

export function guestRead(discussionId: number, start: number, end: number) {
  if (!guestCounting() || !discussionId || end < start) return;

  if (pending && pending.discussion !== discussionId) flush();
  if (!pending) pending = { discussion: discussionId, numbers: new Set() };

  for (let n = start; n <= end && pending.numbers.size < 60; n++) pending.numbers.add(n);
}

export function flush() {
  if (!pending || !pending.numbers.size) {
    pending = null;
    return;
  }

  post({ type: 'seen', discussion: pending.discussion, numbers: Array.from(pending.numbers) });
  pending = null;
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
  window.addEventListener('pagehide', flush);
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') flush();
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
