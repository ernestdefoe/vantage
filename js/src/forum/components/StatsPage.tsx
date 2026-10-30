import UserPage from 'flarum/forum/components/UserPage';
import Dashboard from './Dashboard';

declare const m: import('mithril').Static;

/** Profile → Stats. Its own chunk. */
export default class StatsPage extends UserPage {
  oninit(vnode: any) {
    super.oninit(vnode);
    this.loadUser(m.route.param('username'));
  }

  content() {
    return this.user ? <Dashboard key={this.user.id()} user={this.user} /> : null;
  }
}
