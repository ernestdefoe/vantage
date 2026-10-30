<?php

namespace ErnestDefoe\Vantage;

use ErnestDefoe\Vantage\Tracking\Country;
use ErnestDefoe\Vantage\Tracking\RequestHolder;
use Flarum\Foundation\AbstractServiceProvider;

class ServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        // One per process: the current request, the open country file and
        // the per-request post-views batch are shared, not rebuilt.
        $this->container->singleton(RequestHolder::class);
        $this->container->singleton(Country::class);
        $this->container->singleton(PostViews::class);
    }
}
