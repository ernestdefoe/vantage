import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import CommentPost from 'flarum/forum/components/CommentPost';
import UserPage from 'flarum/forum/components/UserPage';
import SessionDropdown from 'flarum/forum/components/SessionDropdown';
import LinkButton from 'flarum/common/components/LinkButton';
import ItemList from 'flarum/common/utils/ItemList';
import abbreviateNumber from 'flarum/common/utils/abbreviateNumber';
import Icon from 'flarum/common/components/Icon';
import { guestProfile, listen, unwatch, watch } from './tracker';
import { settingsItems } from './settings';

declare const m: import('mithril').Static;

const t = (key: string, params?: Record<string, unknown>) => app.translator.trans('ernestdefoe-vantage.forum.' + key, params as any);

app.initializers.add('ernestdefoe/vantage', () => {
  // 🚨 The dashboard is its own chunk: nothing in it loads on other pages.
  app.routes['user.vantage'] = { path: '/u/:username/stats', component: () => import('./components/StatsPage') };

  // The free number: views under each post, from the payload the page already has.
  extend(CommentPost.prototype, 'footerItems', function (this: any, items: ItemList<any>) {
    const views = this.attrs.post.attribute('vantageViews');
    if (typeof views !== 'number' || views < 1) return;

    items.add(
      'vantageViews',
      m('span.VantageViews', { title: t('post_views', { count: views }) }, [m(Icon, { name: 'far fa-eye' }), ' ', abbreviateNumber(views)]),
      -20
    );
  });

  const canSee = (user: any) => {
    if (!user || !app.session.user) return false;
    return user === app.session.user ? true : !!app.forum.attribute('vantageCanViewOthers');
  };

  extend(UserPage.prototype, 'navItems', function (this: any, items: ItemList<any>) {
    if (!canSee(this.user)) return;

    items.add(
      'vantage',
      LinkButton.component({ href: app.route('user.vantage', { username: this.user.slug() }), icon: 'fas fa-chart-line' }, t('nav')),
      70
    );
  });

  extend(SessionDropdown.prototype, 'items', function (items: ItemList<any>) {
    const user = app.session.user;
    if (!user || !app.forum.attribute('vantageCanView')) return;

    items.add(
      'vantage',
      LinkButton.component({ href: app.route('user.vantage', { username: user.slug() }), icon: 'fas fa-chart-line' }, t('session_item')),
      90
    );
  });

  // Views: each post in a discussion counts once it has been on screen for a
  // second (see tracker.ts). Not your own posts: nobody views their own work.
  extend(CommentPost.prototype, 'oncreate', function (this: any) {
    const post = this.attrs.post;
    const me = app.session.user;
    if (me && post.user?.() === me) return;

    watch(this.element, Number(post.discussion?.()?.id?.()), Number(post.number?.()));
  });
  extend(CommentPost.prototype, 'onremove', function (this: any) {
    unwatch(this.element);
  });

  extend(UserPage.prototype, 'show', function (this: any, _r: unknown, user: any) {
    if (user?.id?.()) guestProfile(user.id());
  });

  // SettingsPage is a lazy chunk in Flarum 2, so it is extended by path.
  extend('flarum/forum/components/SettingsPage', 'settingsItems', function (items: ItemList<any>) {
    settingsItems(items);
  });

  listen();
});
