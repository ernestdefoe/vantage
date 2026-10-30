<?php

/*
 * Vantage — account analytics for every member.
 *
 * Counting rides on requests the forum already makes (the read tracker, the
 * profile fetch); what only a browser knows (guest reads, share clicks) is
 * one small POST. Everything lands in a buffer that a rollup folds into
 * daily history, and the dashboard is one lazy chunk and one endpoint.
 */

use ErnestDefoe\Vantage\Api;
use ErnestDefoe\Vantage\Console\CountryDatabaseCommand;
use ErnestDefoe\Vantage\Console\RollupCommand;
use ErnestDefoe\Vantage\Gdpr\VantageData;
use ErnestDefoe\Vantage\Listener;
use ErnestDefoe\Vantage\PostViews;
use ErnestDefoe\Vantage\Settings;
use ErnestDefoe\Vantage\Tracking\CaptureRequest;
use ErnestDefoe\Vantage\Tracking\CountProfileVisit;
use ErnestDefoe\Vantage\Tracking\RequestHolder;
use ErnestDefoe\Vantage\Tracking\Visitor;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Resource\PostResource;
use Flarum\Api\Resource\UserResource;
use Flarum\Api\Schema;
use Flarum\Discussion\Event\UserRead;
use Flarum\Extend;
use Flarum\Gdpr\Extend\UserData;
use Flarum\Post\CommentPost;
use Flarum\Post\Event\Posted;
use Flarum\Post\Event\Revised;
use Flarum\Post\Post;
use Illuminate\Console\Scheduling\Event;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->css(__DIR__.'/less/forum.less')
        // 🚨 Publishes the lazy dashboard chunk; without it the tab 404s.
        ->jsDirectory(__DIR__.'/js/dist/forum'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\ServiceProvider())
        ->register(\ErnestDefoe\Vantage\ServiceProvider::class),

    (new Extend\Middleware('api'))
        ->add(CaptureRequest::class),

    (new Extend\Event())
        ->listen(UserRead::class, Listener\CountReads::class)
        ->listen(Posted::class, Listener\ClassifyPost::class)
        ->listen(Revised::class, Listener\ClassifyPost::class),

    (new Extend\Conditional())
        ->whenExtensionEnabled('ianm-follow-users', fn () => [
            (new Extend\Event())->listen('IanM\FollowUsers\Events\Unfollowing', Listener\CountUnfollow::class),
        ])
        ->whenExtensionEnabled('flarum-gdpr', fn () => [
            (new UserData())->addType(VantageData::class),
        ]),

    (new Extend\Routes('api'))
        ->post('/vantage/hit', 'vantage.hit', Api\HitController::class)
        ->get('/vantage/{id:[0-9]+}/export', 'vantage.export', Api\ExportController::class)
        ->get('/vantage/{id:[0-9]+}', 'vantage.stats', Api\StatsController::class),

    /*
     * 🚨 The hit endpoint is CSRF-exempt on purpose. Guests on edge-cached
     * pages have no session token, and a page that is closing can't fetch
     * one. Nothing it does changes anyone's data: it can only add a count,
     * once per reader per item per day, under a daily cap, for posts the
     * caller could already see.
     */
    (new Extend\Csrf())
        ->exemptRoute('vantage.hit'),

    (new Extend\ApiResource(UserResource::class))
        ->endpoint('show', fn (Endpoint\Show $endpoint) => $endpoint->after(CountProfileVisit::class)),

    /*
     * The free number under every post. One query for the whole page of posts
     * (see PostViews), and none at all when the viewer may not see it.
     */
    (new Extend\ApiResource(PostResource::class))
        ->fields(fn () => [
            Schema\Integer::make('vantageViews')
                ->nullable()
                ->visible(function (Post $post, Context $context) {
                    // Not on the discussion list: it carries first and most
                    // relevant posts, and never shows their views.
                    if (! $post instanceof CommentPost || $context->listing(DiscussionResource::class)) {
                        return false;
                    }

                    $mode = resolve(Settings::class)->postViews();
                    $actor = $context->getActor();

                    return match ($mode) {
                        'everyone' => $actor->hasPermission('vantage.viewPostStats'),
                        'author' => ! $actor->isGuest() && $actor->id === (int) $post->user_id,
                        default => false,
                    };
                })
                ->get(function (Post $post, Context $context) {
                    $views = resolve(PostViews::class);
                    $views->queue($context->request, (int) $post->id);

                    return fn () => $views->get($context->request, (int) $post->id);
                }),
        ]),

    (new Extend\ApiResource(ForumResource::class))
        ->fields(fn () => [
            Schema\Boolean::make('vantageCanView')
                ->get(fn ($forum, Context $context) => $context->getActor()->hasPermission('vantage.viewDashboard')),
            Schema\Boolean::make('vantageCanViewOthers')
                ->get(fn ($forum, Context $context) => $context->getActor()->hasPermission('vantage.viewOthers')),
        ]),

    (new Extend\User())
        ->registerPreference('vantageNoCount', fn ($v) => (bool) $v, false)
        ->registerPreference('vantageShareAudience', fn ($v) => (bool) $v, false)
        ->registerPreference('vantageBirthYear', function ($v) {
            $year = (int) $v;

            return $year >= 1900 && $year <= (int) date('Y') ? $year : null;
        }, null)
        ->registerPreference('vantageGender', fn ($v) => in_array($v, Visitor::GENDERS, true) ? $v : null, null),

    (new Extend\Settings())
        ->serializeToForum('vantageGuests', Settings::PREFIX.'count_guests', fn ($v) => $v === null ? true : (bool) (int) $v)
        ->serializeToForum('vantageDemographics', Settings::PREFIX.'demographics', fn ($v) => (bool) (int) $v)
        ->default(Settings::PREFIX.'count_guests', true)
        ->default(Settings::PREFIX.'post_views', 'everyone')
        ->default(Settings::PREFIX.'demographics', false)
        ->default(Settings::PREFIX.'country_source', 'auto')
        ->default(Settings::PREFIX.'country_header', 'CF-IPCountry')
        ->default(Settings::PREFIX.'retention_months', 13),

    (new Extend\Console())
        ->command(RollupCommand::class)
        ->command(CountryDatabaseCommand::class)
        ->schedule(RollupCommand::class, function (Event $event): void {
            $event->everyFifteenMinutes()->withoutOverlapping();
        })
        ->schedule(CountryDatabaseCommand::class, function (Event $event): void {
            $event->dailyAt('04:17')->withoutOverlapping();
        }),
];
