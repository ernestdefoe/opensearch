import app from 'flarum/admin/app';
import Admin from 'flarum/common/extenders/Admin';
import OpenSearchControls from './components/OpenSearchControls';

declare const m: import('mithril').Static;

const K = 'ernestdefoe-opensearch';
const t = (k: string) => app.translator.trans(`${K}.admin.${k}`);

// Each .setting()/.customSetting() takes a FUNCTION — the Flarum 2 Admin
// extender contract that replaced the removed 1.x `app.extensionData` API.
// The plain connection fields are declared here; the password, TLS toggle,
// driver switches and action buttons live in the custom controls component so
// the password is masked and the buttons can reach this extension's endpoints.
export default [
  new Admin()
    .setting(() => ({
      setting: `${K}.url`,
      type: 'text',
      label: t('url_label'),
      help: t('url_help'),
      placeholder: 'https://localhost:9200',
    }))
    .setting(() => ({
      setting: `${K}.username`,
      type: 'text',
      label: t('username_label'),
      help: t('username_help'),
    }))
    .setting(() => ({
      setting: `${K}.index_prefix`,
      type: 'text',
      label: t('index_prefix_label'),
      help: t('index_prefix_help'),
    }))
    .customSetting(function (this: { setting: (k: string, d?: string) => (v?: string) => string }) {
      return m(OpenSearchControls, { setting: (k: string, d?: string) => this.setting(k, d) });
    }),
];
