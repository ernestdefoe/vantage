<?php

namespace ErnestDefoe\Vantage\Gdpr;

use Flarum\Gdpr\Data\Type;

/**
 * A member's own statistics. Readers have nothing here to export or erase:
 * Vantage never stores who read, visited or shared what.
 */
class VantageData extends Type
{
    public static function exportDescription(): string
    {
        return self::staticTranslator()->trans('ernestdefoe-vantage.lib.gdpr.export_description');
    }

    public static function anonymizeDescription(): string
    {
        return self::deleteDescription();
    }

    public static function deleteDescription(): string
    {
        return self::staticTranslator()->trans('ernestdefoe-vantage.lib.gdpr.delete_description');
    }

    public function export(): ?array
    {
        $db = $this->user->getConnection();

        $daily = $db->table('vantage_daily')
            ->where('user_id', $this->user->id)
            ->orderBy('day')
            ->get(['metric', 'day', 'span', 'dim', 'value'])
            ->map(fn ($row) => (array) $row)
            ->all();

        $views = $db->table('vantage_post_views')
            ->join('posts', 'posts.id', '=', 'vantage_post_views.post_id')
            ->where('posts.user_id', $this->user->id)
            ->get(['vantage_post_views.post_id', 'vantage_post_views.views'])
            ->map(fn ($row) => (array) $row)
            ->all();

        return [
            ['vantage/statistics.json' => $this->encodeForExport($daily)],
            ['vantage/post-views.json' => $this->encodeForExport($views)],
        ];
    }

    public function anonymize(): void
    {
        $this->delete();
    }

    public function delete(): void
    {
        $db = $this->user->getConnection();

        $db->table('vantage_daily')->where('user_id', $this->user->id)->delete();
        $db->table('vantage_buffer')->where('user_id', $this->user->id)->delete();
        $db->table('vantage_post_views')
            ->whereIn('post_id', $db->table('posts')->select('id')->where('user_id', $this->user->id))
            ->delete();
    }
}
