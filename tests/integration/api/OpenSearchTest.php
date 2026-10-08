<?php

namespace Ernestdefoe\OpenSearch\Tests\integration\api;

use Carbon\Carbon;
use Ernestdefoe\OpenSearch\OpenSearchConnection;
use Ernestdefoe\OpenSearch\Tests\integration\FakeCluster;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ServerRequestInterface;

class OpenSearchTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-opensearch');

        $this->prepareDatabase([
            User::class => [$this->normalUser()],
            Discussion::class => [
                ['id' => 1, 'title' => 'First', 'created_at' => Carbon::now(), 'user_id' => 1, 'first_post_id' => 1, 'comment_count' => 1, 'last_post_number' => 1],
                ['id' => 2, 'title' => 'Hidden', 'created_at' => Carbon::now(), 'user_id' => 1, 'first_post_id' => 2, 'comment_count' => 1, 'last_post_number' => 1, 'hidden_at' => Carbon::now()],
                ['id' => 3, 'title' => 'Third', 'created_at' => Carbon::now(), 'user_id' => 1, 'first_post_id' => 3, 'comment_count' => 1, 'last_post_number' => 1],
                ['id' => 4, 'title' => 'Fourth', 'created_at' => Carbon::now(), 'user_id' => 1, 'first_post_id' => 4, 'comment_count' => 1, 'last_post_number' => 1],
            ],
            Post::class => [
                ['id' => 1, 'discussion_id' => 1, 'number' => 1, 'created_at' => Carbon::now(), 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>One</p></t>'],
                ['id' => 2, 'discussion_id' => 2, 'number' => 1, 'created_at' => Carbon::now(), 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>Two</p></t>'],
                ['id' => 3, 'discussion_id' => 3, 'number' => 1, 'created_at' => Carbon::now(), 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>Three</p></t>'],
                ['id' => 4, 'discussion_id' => 4, 'number' => 1, 'created_at' => Carbon::now(), 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>Four</p></t>'],
            ],
        ]);
    }

    /** Swap the cluster for a script, before the first request. */
    private function cluster(): FakeCluster
    {
        $container = $this->app()->getContainer();
        $fake = $container->make(FakeCluster::class);
        $container->instance(OpenSearchConnection::class, $fake);

        return $fake;
    }

    private function useForDiscussions(): void
    {
        $this->setting('search_driver_'.Discussion::class, 'opensearch');
    }

    private function json(string $method, string $path, ?int $actor = null, array $options = []): array
    {
        $request = $this->request($method, $path, $options + ($actor ? ['authenticatedAs' => $actor] : []));

        if ($method !== 'GET' && ! $actor) {
            $request = $this->withGuestSession($request);
        }

        $response = $this->send($request);

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    /** A guest's write needs a session and its CSRF token, as a browser has. */
    private function withGuestSession(ServerRequestInterface $request): ServerRequestInterface
    {
        $initial = $this->send($this->request('GET', '/api'));

        return $this->requestWithCookiesFrom($request->withHeader('X-CSRF-Token', $initial->getHeaderLine('X-CSRF-Token')), $initial);
    }

    /** @return list<string> discussion ids a fulltext search returns, in order */
    private function search(string $q, ?int $actor = null): array
    {
        $request = $this->request('GET', '/api/discussions', $actor ? ['authenticatedAs' => $actor] : [])
            ->withQueryParams(['filter' => ['q' => $q]]);
        $response = $this->send($request);
        $this->assertSame(200, $response->getStatusCode());

        return array_column(json_decode((string) $response->getBody(), true)['data'], 'id');
    }

    #[Test]
    public function only_an_admin_can_check_the_connection_or_start_a_rebuild()
    {
        $this->setting('ernestdefoe-opensearch.url', 'http://cluster.invalid:9200');

        foreach ([null, 2] as $actor) {
            [$status] = $this->json('GET', '/api/opensearch/status', $actor);
            $this->assertSame(403, $status);

            [$status] = $this->json('POST', '/api/opensearch/rebuild', $actor);
            $this->assertSame(403, $status);
        }
    }

    #[Test]
    public function the_status_says_what_is_wrong_without_throwing()
    {
        [$status, $body] = $this->json('GET', '/api/opensearch/status', 1);
        $this->assertSame(200, $status);
        $this->assertSame(['ok' => false, 'error' => 'not_configured'], $body);

        $this->cluster();
        [, $body] = $this->json('GET', '/api/opensearch/status', 1);
        $this->assertSame(['ok' => true, 'distribution' => 'opensearch', 'version' => '3.0.0'], $body);
    }

    #[Test]
    public function a_rebuild_against_an_unreachable_cluster_deletes_nothing()
    {
        [$status, $body] = $this->json('POST', '/api/opensearch/rebuild', 1);
        $this->assertSame(422, $status);
        $this->assertSame('not_configured', $body['error']);

        $cluster = $this->cluster();
        $cluster->down = true;

        [$status] = $this->json('POST', '/api/opensearch/rebuild', 1);
        $this->assertSame(422, $status);
        $this->assertSame([], $cluster->requests, 'An index must never be dropped when it cannot be refilled');
    }

    #[Test]
    public function a_rebuild_refills_every_index_from_visible_content()
    {
        $cluster = $this->cluster();

        [$status, $body] = $this->json('POST', '/api/opensearch/rebuild', 1);

        // The sync queue runs the job inside the request.
        $this->assertSame(202, $status);
        $this->assertSame('queued', $body['status']);

        foreach (['discussions', 'users', 'posts'] as $index) {
            $this->assertContains("DELETE /localhost_$index", $cluster->calls());
            $this->assertContains("PUT /localhost_$index", $cluster->calls());
            $this->assertContains("POST /localhost_$index/_refresh", $cluster->calls());
        }

        // Unique: the admin's own last-seen update re-indexes them as well.
        $indexed = array_values(array_unique(array_map(fn ($a) => $a[1].'#'.$a[2], $cluster->bulkActions())));
        $this->assertEqualsCanonicalizing(
            // The hidden discussion is left out of the discussions index. Its
            // post is indexed, and post search applies visibility per actor.
            ['localhost_discussions#1', 'localhost_discussions#3', 'localhost_discussions#4', 'localhost_users#1', 'localhost_users#2', 'localhost_posts#1', 'localhost_posts#2', 'localhost_posts#3', 'localhost_posts#4'],
            $indexed
        );
    }

    #[Test]
    public function the_forum_says_which_searches_opensearch_answers()
    {
        [, $body] = $this->json('GET', '/api');
        $this->assertSame([], $body['data']['attributes']['openSearchSearch']);

        $settings = $this->app()->getContainer()->make(SettingsRepositoryInterface::class);
        $settings->set('search_driver_'.Discussion::class, 'opensearch');
        $settings->set('search_driver_'.Post::class, 'opensearch');

        [, $body] = $this->json('GET', '/api');
        $this->assertSame(['discussions', 'posts'], $body['data']['attributes']['openSearchSearch']);
        $this->assertStringNotContainsString('opensearch.url', json_encode($body));
    }

    #[Test]
    public function search_keeps_relevance_order_and_never_returns_what_the_actor_cannot_see()
    {
        $this->useForDiscussions();
        $cluster = $this->cluster();

        $cluster->hits = [3, 2, 1];

        // Discussion 4 is visible but not a hit.
        $this->assertSame(['3', '1'], $this->search('anything'), 'The cluster narrows; permissions still decide');
        $this->assertSame(['3', '2', '1'], $this->search('anything', 1));
        $this->assertContains('POST /localhost_discussions/_search', $cluster->calls());
    }

    #[Test]
    public function a_cluster_that_is_down_returns_no_results_rather_than_an_error()
    {
        $this->useForDiscussions();
        $cluster = $this->cluster();
        $cluster->down = true;

        $this->assertSame([], $this->search('anything'));
    }

    #[Test]
    public function the_index_prefix_keeps_forums_on_one_cluster_apart()
    {
        $this->useForDiscussions();
        $this->setting('ernestdefoe-opensearch.index_prefix', 'My Forum');
        $cluster = $this->cluster();

        $this->search('anything');

        $this->assertContains('POST /my_forum_discussions/_search', $cluster->calls());
    }

    #[Test]
    public function a_reply_and_its_hiding_reach_both_indexes()
    {
        $cluster = $this->cluster();

        [$status, $body] = $this->json('POST', '/api/posts', 2, ['json' => ['data' => [
            'type' => 'posts',
            'attributes' => ['content' => 'A reply'],
            'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => '1']]],
        ]]]);
        $this->assertSame(201, $status);
        $postId = $body['data']['id'];

        $this->assertContains(['index', 'localhost_posts', $postId], $cluster->bulkActions(), 'A CommentPost reaches the posts index');
        $this->assertContains(['index', 'localhost_discussions', '1'], $cluster->bulkActions(), 'and refreshes its discussion');

        // Flarum 2.0 throttles a content edit made within seconds of the
        // author's last post, so the reply is dated a minute back first.
        Post::query()->whereKey($postId)->update(['created_at' => Carbon::now()->subMinute()]);

        // An edit saves only the post, so the discussion's document is
        // refreshed through the post.
        $cluster->requests = [];
        [$status] = $this->json('PATCH', "/api/posts/$postId", 2, ['json' => ['data' => [
            'type' => 'posts', 'id' => $postId, 'attributes' => ['content' => 'An edited reply'],
        ]]]);
        $this->assertSame(200, $status);
        $this->assertContains(['index', 'localhost_posts', $postId], $cluster->bulkActions());
        $this->assertContains(['index', 'localhost_discussions', '1'], $cluster->bulkActions(), 'An edit refreshes its discussion');

        $cluster->requests = [];
        [$status] = $this->json('PATCH', "/api/posts/$postId", 1, ['json' => ['data' => [
            'type' => 'posts', 'id' => $postId, 'attributes' => ['isHidden' => true],
        ]]]);
        $this->assertSame(200, $status);
        $this->assertContains(['delete', 'localhost_posts', $postId], $cluster->bulkActions(), 'Hiding a post takes it out of search');
    }

    #[Test]
    public function an_unconfigured_cluster_never_gets_in_the_way_of_posting()
    {
        [$status] = $this->json('POST', '/api/posts', 2, ['json' => ['data' => [
            'type' => 'posts',
            'attributes' => ['content' => 'A reply'],
            'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => '1']]],
        ]]]);

        $this->assertSame(201, $status);
    }
}
