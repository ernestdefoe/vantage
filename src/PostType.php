<?php

namespace ErnestDefoe\Vantage;

use Flarum\Post\Post;
use Illuminate\Database\ConnectionInterface;

/**
 * Text, image or video, read from a post's stored XML once, when it is saved.
 * A video wins over an image: a post with both is "a video post".
 */
class PostType
{
    public const TYPES = ['text', 'image', 'video'];

    /** s9e MediaEmbed sites people post video from, plus uploads and <video>. */
    private const VIDEO = '/<(VIDEO|YOUTUBE|VIMEO|TWITCH|DAILYMOTION|TIKTOK|STREAMABLE|BILIBILI|RUMBLE|KICK|LOOM|WISTIA|ODYSEE|PEERTUBE|UPL-VIDEO)[\s>]/i';

    private const IMAGE = '/<(IMG|UPL-IMAGE|UPL-IMAGE-PREVIEW|IMGUR|GIPHY|FLICKR|INSTAGRAM)[\s>]/i';

    public function __construct(private ConnectionInterface $db)
    {
    }

    public static function of(string $xml): string
    {
        return match (true) {
            (bool) preg_match(self::VIDEO, $xml) => 'video',
            (bool) preg_match(self::IMAGE, $xml) => 'image',
            default => 'text',
        };
    }

    public function store(Post $post): void
    {
        if ($post->type !== 'comment' || ! $post->id) {
            return;
        }

        $this->db->table('vantage_post_types')->upsert(
            [['post_id' => $post->id, 'type' => self::of((string) ($post->getAttributes()['content'] ?? ''))]],
            ['post_id'],
            ['type']
        );
    }

    /**
     * Types existing posts that have none yet, a slice at a time, newest
     * first (the ones a dashboard is most likely to ask about).
     */
    public function backfill(int $limit = 1000): int
    {
        $rows = $this->db->table('posts')
            ->leftJoin('vantage_post_types', 'vantage_post_types.post_id', '=', 'posts.id')
            ->whereNull('vantage_post_types.post_id')
            ->where('posts.type', 'comment')
            ->orderByDesc('posts.id')
            ->limit($limit)
            ->get(['posts.id', 'posts.content']);

        if ($rows->isEmpty()) {
            return 0;
        }

        $this->db->table('vantage_post_types')->insertOrIgnore(
            $rows->map(fn ($row) => ['post_id' => (int) $row->id, 'type' => self::of((string) $row->content)])->all()
        );

        return $rows->count();
    }
}
