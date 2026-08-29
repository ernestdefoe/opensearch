import app from 'flarum/admin/app';

// Settings are declared via the JS Admin extender in ./extend — the Flarum 2
// pattern that replaced the removed 1.x `app.extensionData` API (using that API
// throws "failed to initialize").
app.initializers.add('ernestdefoe-opensearch', () => {});

export { default as extend } from './extend';
