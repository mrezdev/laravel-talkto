<?php

namespace Mrezdev\LaravelTalkto\Support\Panel;

/**
 * @internal Optional panel implementation detail.
 */
enum TalktoPanelHealthStatus: string
{
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Failing = 'failing';
    case Misconfigured = 'misconfigured';
    case Unknown = 'unknown';
}
