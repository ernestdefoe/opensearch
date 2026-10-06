<?php

use Illuminate\Database\Schema\Builder;

/*
 * The default index prefix used to come from a `forum_url` setting that
 * Flarum 2 does not have, so every install indexed under "flarum_". It now
 * comes from config.php's url. An install that was already configured (a
 * cluster URL is stored) and never chose a prefix has its indices under the
 * old default: store exactly that prefix for it, so upgrading does not orphan
 * the index and silently empty its search. Fresh installs store nothing and
 * get the per-forum default.
 */
return [
    'up' => function (Builder $schema) {
        $db = $schema->getConnection();
        $value = fn (string $key) => trim((string) $db->table('settings')->where('key', $key)->value('value'));

        if ($value('ernestdefoe-opensearch.index_prefix') === '' && $value('ernestdefoe-opensearch.url') !== '') {
            // Exactly what the old default resolved to.
            $old = parse_url($value('forum_url'), PHP_URL_HOST) ?: 'flarum';

            $db->table('settings')->updateOrInsert(
                ['key' => 'ernestdefoe-opensearch.index_prefix'],
                ['value' => $old]
            );
        }
    },

    'down' => function (Builder $schema) {
        // Nothing to undo: the stored prefix is the one the index was built under.
    },
];
