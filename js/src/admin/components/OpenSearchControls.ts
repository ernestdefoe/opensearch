import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Switch from 'flarum/common/components/Switch';

declare const m: import('mithril').Static;

const K = 'ernestdefoe-opensearch';
// Core stores the per-model search driver under `search_driver_<ModelClass>`;
// the backslashes are literal in the stored key.
const DRIVERS: { key: string; label: string }[] = [
  { key: 'search_driver_Flarum\\Discussion\\Discussion', label: 'use_for_discussions' },
  { key: 'search_driver_Flarum\\User\\User', label: 'use_for_users' },
  { key: 'search_driver_Flarum\\Post\\Post', label: 'use_for_posts' },
];
const t = (k: string) => app.translator.trans(`${K}.admin.${k}`);

interface Attrs {
  setting: (k: string, d?: string) => (v?: string) => string;
}

export default class OpenSearchControls extends Component<Attrs> {
  testing = false;
  testStatus: 'ok' | 'fail' | null = null;
  testDetail = '';
  rebuilding = false;

  view() {
    const setting = this.attrs.setting;
    const password = setting(`${K}.password`, '');
    const verifyTls = setting(`${K}.verify_tls`, '1');

    return m('div', [
      m('.Form-group', [
        m('label', t('password_label')),
        m('input.FormControl', {
          type: 'password',
          value: password(),
          oninput: (e: InputEvent) => password((e.target as HTMLInputElement).value),
          placeholder: '••••••••',
        }),
      ]),

      m(
        '.Form-group',
        m(
          Switch,
          {
            state: verifyTls() !== '0',
            onchange: (v: boolean) => verifyTls(v ? '1' : '0'),
          },
          t('verify_tls_label')
        ),
        m('.helpText', t('verify_tls_help'))
      ),

      m('hr'),

      m('.Form-group', [
        m('label', t('drivers_label')),
        m('.helpText', t('drivers_help')),
        ...DRIVERS.map(({ key, label }) => {
          const driver = setting(key, 'default');
          return m(
            'div',
            { style: 'margin: 6px 0' },
            m(
              Switch,
              {
                state: driver() === 'opensearch',
                onchange: (v: boolean) => driver(v ? 'opensearch' : 'default'),
              },
              t(label)
            )
          );
        }),
      ]),

      m('hr'),

      m('.Form-group', [
        m('label', t('connection_label')),
        m('div', [
          m(Button, { className: 'Button', loading: this.testing, onclick: () => this.test() }, t('test_button')),
          ' ',
          this.testStatus === 'ok'
            ? m('span', { style: 'color: var(--success-color, green)' }, ['✓ ', t('test_ok'), this.testDetail ? ` (${this.testDetail})` : ''])
            : this.testStatus === 'fail'
            ? m('span', { style: 'color: var(--error-color, #d83e3e)' }, ['✗ ', t('test_fail'), this.testDetail ? ` (${this.testDetail})` : ''])
            : null,
        ]),
      ]),

      m('.Form-group', [
        m('label', t('rebuild_label')),
        m('.helpText', t('rebuild_help')),
        m(Button, { className: 'Button', loading: this.rebuilding, onclick: () => this.rebuild() }, t('rebuild_button')),
      ]),
    ]);
  }

  apiUrl(path: string): string {
    return `${app.forum.attribute('apiUrl')}${path}`;
  }

  test() {
    this.testing = true;
    this.testStatus = null;
    this.testDetail = '';

    app
      .request<{ ok?: boolean; error?: string; distribution?: string; version?: string }>({
        method: 'GET',
        url: this.apiUrl('/opensearch/status'),
      })
      .then((res) => {
        this.testStatus = res && res.ok ? 'ok' : 'fail';
        // Naming the distribution and version on success is worth the space:
        // it is the difference between "something answered" and "the cluster
        // you meant answered".
        this.testDetail = res && res.ok ? [res.distribution, res.version].filter(Boolean).join(' ') : (res && res.error) || '';
      })
      .catch(() => {
        this.testStatus = 'fail';
      })
      .then(() => {
        this.testing = false;
        m.redraw();
      });
  }

  rebuild() {
    this.rebuilding = true;

    app
      .request({ method: 'POST', url: this.apiUrl('/opensearch/rebuild') })
      .then(() => app.alerts.show({ type: 'success' }, t('rebuild_queued')))
      .catch(() => app.alerts.show({ type: 'error' }, t('rebuild_failed')))
      .then(() => {
        this.rebuilding = false;
        m.redraw();
      });
  }
}
