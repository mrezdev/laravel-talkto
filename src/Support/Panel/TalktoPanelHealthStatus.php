<?php

namespace Mrezdev\LaravelTalkto\Support\Panel;

enum TalktoPanelHealthStatus: string
{
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Failing = 'failing';
    case Misconfigured = 'misconfigured';
    case Unknown = 'unknown';
}
