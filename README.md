# OpenSearch for Flarum

[![Latest Stable Version](https://img.shields.io/packagist/v/ernestdefoe/opensearch.svg)](https://packagist.org/packages/ernestdefoe/opensearch)
[![Total Downloads](https://img.shields.io/packagist/dt/ernestdefoe/opensearch.svg)](https://packagist.org/packages/ernestdefoe/opensearch)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE.md)

A search driver for **Flarum 2** backed by **OpenSearch** — or Elasticsearch, which
speaks the same REST API for everything used here.

Flarum's built-in search is a `LIKE` scan of the database. It works, and on a small
forum you will never notice anything else. It also cannot rank, cannot tolerate a
typo, and gets slower with every discussion you add. This routes text searches to a
real search engine instead, and leaves everything else exactly as it was.

## What it does

- **Discussions, posts and users** — turn each on independently. Anything you leave
  off keeps using the database driver.
- **Ranked results.** A thread *titled* "Redis caching" outranks one that mentions
  the phrase in a reply.
- **Typo tolerance.** One edit's slack on longer words; short words stay exact, so
  `cat` never quietly matches `car`.
- **Accent folding.** `jose` finds `José`.
- **Incremental indexing.** Posting, editing, deleting and renaming keep the index
  current — you rebuild once at setup, not on a schedule. Flarum queues that work,
  so it needs a running queue worker (see below).
- **Works with your other extensions.** Filters registered against core's searchers
  are mirrored across, so `tag:support` refinement from flarum/tags keeps working.

## What it does not do

**It never decides who can see what.** Flarum applies `whereVisibleTo` to every
search before results are returned, so the cluster only ranks candidates the member
could already read. A stale or over-broad index cannot leak a private discussion.

It also does not replace filtering or browsing. Core routes only *text* searches to
a driver; sorting a tag by newest never touches the cluster.

## Installation

```bash
composer require ernestdefoe/opensearch
```

Then, under **Admin → OpenSearch**:

1. Enter your **cluster URL** (`https://localhost:9200`), plus a username and
   password if your cluster requires them.
2. Press **Test connection** — it names the distribution and version it reached, so
   you know it is the cluster you meant.
3. Press **Rebuild index**, or run it from the CLI (see below).
4. Turn on the searches you want OpenSearch to answer.

Do step 3 before step 4. Enabling the driver against an empty index means every
search returns nothing.

## Keeping the index current

Flarum hands indexing work to its queue. On the default `sync` queue that runs
inline and there is nothing to configure. If you have set `queue_driver` to Redis
or the database — which most forums of any size have — **those jobs only run when a
queue worker is running:**

```bash
php flarum queue:work
```

Without one, new and edited posts are queued but never indexed, and search quietly
serves stale results. This is core Flarum behaviour and applies to every search
driver, but it is worth stating plainly, because nothing warns you.

## Rebuilding from the CLI

The admin button queues a job; on the default `sync` queue that still runs inside a
web request, which will time out on a large forum. Prefer the command:

```bash
php flarum opensearch:index
```

```bash
php flarum opensearch:index --only=discussions
```

```bash
php flarum opensearch:index --flush
```

`--flush` deletes the indexes without rebuilding them — useful when you are turning
the extension off, or starting clean.

## A note on TLS

**Verify the TLS certificate** is on by default and should stay on for any cluster
reached over the public internet. Turn it off only for a cluster on a private or
container network using a self-signed certificate, which is the common self-hosted
case.

## Requirements

- Flarum ^2.0
- PHP ^8.2
- OpenSearch 1.x/2.x/3.x, or Elasticsearch 7.x/8.x

No PHP client library is required — the extension talks to the cluster's REST API
directly.

## Links

- [Packagist](https://packagist.org/packages/ernestdefoe/opensearch)
- [GitHub](https://github.com/ernestdefoe/opensearch)
- [Report a bug](https://github.com/ernestdefoe/opensearch/issues)

## License

[MIT](LICENSE.md) — Ernest Defoe
