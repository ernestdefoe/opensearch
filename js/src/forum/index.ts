import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import type ItemList from 'flarum/common/utils/ItemList';
import type Mithril from 'mithril';

declare const m: import('mithril').Static;

const K = 'ernestdefoe-opensearch';

app.initializers.add(K, () => {
  // The forum document lists which search resources are routed to OpenSearch
  // (booleans only — the cluster URL and password never reach the browser).
  // Show a small badge in the search modal footer on exactly those tabs.
  //
  // SearchModal is lazy-loaded (app.modal.show(() => import(...))), so extend
  // it by module path — core applies the extension via flarum.reg.onLoad once
  // the chunk arrives. Extending the prototype directly would run before the
  // module exists and silently do nothing.
  extend('flarum/common/components/SearchModal', 'activeTabItems', function (this: any, items: ItemList<Mithril.Children>) {
    const resources = (app.forum.attribute<string[]>('openSearchSearch') || []) as string[];
    const source = this.activeSource?.();

    if (!source || !resources.includes(source.resource)) return;

    items.add(
      'opensearch',
      m('div', { className: 'SearchModal-section OpenSearchBadge-section' }, [
        m('span', { className: 'OpenSearchBadge' }, [
          m('span', { className: 'OpenSearchBadge-icon', 'aria-hidden': 'true' }, m('i', { className: 'fas fa-magnifying-glass-chart' })),
          app.translator.trans(`${K}.forum.powered_by`),
        ]),
      ]),
      0
    );
  });
});
