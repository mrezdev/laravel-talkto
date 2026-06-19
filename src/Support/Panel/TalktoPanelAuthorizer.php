<?php

namespace Mrezdev\LaravelTalkto\Support\Panel;

use Illuminate\Support\Facades\Gate;

class TalktoPanelAuthorizer
{
    public function authorize(): void
    {
        if (! (bool) config('talkto.panel.authorization.enabled', true)) {
            return;
        }

        $gate = config('talkto.panel.authorization.gate', 'viewTalktoPanel');
        $gate = is_string($gate) && trim($gate) !== '' ? $gate : 'viewTalktoPanel';

        abort_unless(Gate::allows($gate), 403);
    }
}
