# [Free] OpenSearch — a real search engine for Flarum 2

Flarum's built-in search is a `LIKE` scan of the database. It works, and on a small forum you will never notice anything else. It also cannot rank, cannot tolerate a typo, and gets slower with every discussion you add.

There are Typesense and Elasticsearch options floating around for Flarum 1, but as far as I could find **nothing that speaks OpenSearch on Flarum 2** — so I built one. It works against Elasticsearch too, since both speak the same REST API for everything this needs.

## What it does

- **Discussions, posts and users** — each switchable independently. Anything you leave off keeps using the database driver, so you can move one at a time.
- **Ranked results.** A thread *titled* "Redis caching" outranks one that mentions the phrase once in a reply.
- **Typo tolerance.** One edit's slack on longer words; short words stay exact, so `cat` never quietly matches `car`.
- **Accent folding.** `jose` finds `José`.
- **Incremental indexing.** Posting, editing, renaming and deleting keep the index current through Flarum's queue — you rebuild once at setup, not on a schedule.
- **Plays well with your other extensions.** Filters registered against core's searchers are mirrored across, so `tag:` refinement from flarum/tags keeps working exactly as it does on the database driver.

## It never decides who can see what

This is the part I would want to know about before pointing my forum's search at an external service.

Flarum applies `whereVisibleTo` to every search *before* results are returned. The cluster only ranks candidates the member could already read — it is not consulted about permissions, and it cannot grant them. A stale or over-broad index cannot leak a private discussion.

That is tested rather than assumed: a discussion marked private is present in the index, and a guest searching for it gets nothing back.

## Installation

```bash
composer require ernestdefoe/opensearch
```

Then under **Admin → OpenSearch**:

1. Enter your **cluster URL** (`https://localhost:9200`), plus username and password if your cluster needs them.
2. Press **Test connection** — it reports the distribution and version it actually reached, so you know it is the cluster you meant.
3. Press **Rebuild index**, or run `php flarum opensearch:index`.
4. *Then* turn on the searches you want it to answer.

Do step 3 before step 4. Enabling the driver against an empty index means every search returns nothing.

### Two things worth knowing

**If you use a Redis or database queue, make sure a queue worker is running.** Flarum hands indexing work to the queue, so without a worker new posts are queued and never indexed, and search quietly serves stale results. That is core behaviour common to every search driver, but nothing warns you about it, so I would rather say it here than have you find out.

**Keep TLS verification on** for any cluster reachable over the internet. There is a switch to turn it off, and it is there for self-signed certificates on a private or container network — not for convenience.

## Requirements

- Flarum ^2.0
- PHP ^8.2
- OpenSearch 1.x/2.x/3.x, or Elasticsearch 7.x/8.x

No PHP client library required — it talks to the REST API directly, which keeps a fairly large transport dependency out of your tree.

## Links

- **GitHub:** https://github.com/ernestdefoe/opensearch
- **Packagist:** https://packagist.org/packages/ernestdefoe/opensearch
- **Bug reports / support:** https://ernestdefoe.online/t/opensearch
- **Licence:** [MIT](https://github.com/ernestdefoe/opensearch/blob/main/LICENSE.md)

Free, and MIT, so do what you like with it. If you run it against a cluster setup I have not tried — particularly Elasticsearch, or a managed OpenSearch service — I would genuinely like to hear how it goes.
