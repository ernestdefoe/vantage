# Vantage

Account analytics for every member of your [Flarum 2](https://flarum.org) forum.

Everyone gets a view count under each post. The groups you choose also get a full **Stats** tab on their profile:

- **Views, engagements, engagement rate and profile visits**, each compared with the period before.
- **Rolling periods:** 24 hours, 7, 28, 90 and 365 days, and all time.
- **An interactive chart** of views, replies, likes, reactions, bookmarks, reposts, shares, profile visits, new followers and unfollows.
- **When your audience is around:** a day-by-hour grid in your own time zone.
- **By kind of post:** text, image and video.
- **Your top posts** in the period.
- **Your audience:** countries, and age and gender if you turn that on (see Privacy).
- **Export** to CSV or JSON.

Followers come from [Follow Users](https://github.com/imorland/follow-users), likes from Likes, reactions from FoF Reactions, bookmarks from FoF Bookmarks and reposts from Moments. Each one appears when its extension is enabled. These already have dates, so their history goes back to day one. Views, profile visits, shares and unfollows are counted from the day Vantage is installed.

## Who sees what

| Permission | Default | Gives |
|---|---|---|
| See views under posts | Everyone | The view count under each post (a setting can limit it to the post's author) |
| Use the Stats dashboard | Nobody | The member's own dashboard |
| See any member's Stats dashboard | Moderators | Anyone's dashboard |

Because the dashboard is a group permission, you can give it however you like: by hand, through Kiosk or any other paid-group extension. A member without it sees what the dashboard offers and which groups get it.

## Light on your forum

- **Members are counted without any extra request.** Flarum already saves how far a member has read, and Vantage counts the posts between their old and new position.
- **Guests are counted from their browser:** one small request when they leave a discussion. Crawlers and Global Privacy Control are skipped. A setting turns guest counting off.
- **Counts are written in one batched insert** and folded into daily history every 15 minutes (or when a dashboard is opened, so forums without cron still work).
- **The views under posts cost one query** for the whole page of posts.
- **The script every visitor downloads is about 3.4 KB gzipped.** The dashboard is its own chunk, loaded only when it's opened.

## Privacy

- Vantage **never stores who read, visited or shared what.** Its tables hold the *author's* counts only. Readers are deduplicated with keys that live in the cache for one day; guests are a hash with a daily salt. No IP address is ever written.
- **Members can opt out** in Settings: "Don't count my reads and visits in other members' stats".
- **Countries** come from a proxy's header (Cloudflare's CF-IPCountry) or from DB-IP's free country file, which is downloaded to your server once a month only if needed. Lookups happen on your server. A country with fewer than 5 readers shows as "Other".
- **Age and gender are off by default.** When you turn them on, members can share a birth year and gender in Settings. Nobody is included until they choose to be. Under-18s are never counted, a bar needs 10 readers or more, and the breakdown needs 50.
- With [GDPR](https://github.com/flarum/gdpr), members' statistics are included in their data export and erased with their account.

## Install

```sh
composer require ernestdefoe/vantage
php flarum migrate
php flarum cache:clear
```

Run Flarum's scheduler (`php flarum schedule:run` every minute) for the 15-minute rollup and the monthly country file. Without it, numbers are folded in whenever a dashboard is opened.

## Commands

- `php flarum vantage:rollup` folds waiting counts into history (`--prune` also merges old days into months).
- `php flarum vantage:country-database --force` downloads the DB-IP country file now.

## License

MIT. IP geolocation by [DB-IP](https://db-ip.com) (CC BY 4.0).

☕ If you like the things I build, consider [buying me a coffee](https://buymeacoffee.com/ernestdefoe).
