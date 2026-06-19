<?php

namespace Mrezdev\LaravelTalkto\Http\Controllers\Panel;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Mrezdev\LaravelTalkto\Models\TalktoDeadLetter;
use Mrezdev\LaravelTalkto\Services\Panel\TalktoPanelActionExecutor;
use Mrezdev\LaravelTalkto\Support\Panel\TalktoPanelAuthorizer;

class TalktoPanelDeadLetterActionsController
{
    public function reprocess(
        string $deadLetter,
        Request $request,
        TalktoPanelAuthorizer $authorizer,
        TalktoPanelActionExecutor $actions,
    ): JsonResponse|RedirectResponse {
        $authorizer->authorize();
        $deadLetterModel = $this->findDeadLetter($deadLetter);

        abort_if($deadLetterModel === null, 404);

        $result = $actions->reprocessDeadLetter($deadLetterModel, $request->boolean('force'));

        if ($request->expectsJson()) {
            return response()->json($result->toArray(), $result->success ? 200 : 422);
        }

        return redirect()
            ->back()
            ->with($result->success ? 'talkto_panel_status' : 'talkto_panel_error', $result->message);
    }

    private function findDeadLetter(string $deadLetter): ?TalktoDeadLetter
    {
        if (! ctype_digit($deadLetter)) {
            return null;
        }

        $deadLetterClass = $this->deadLetterModelClass();

        return $deadLetterClass::query()->whereKey((int) $deadLetter)->first();
    }

    private function deadLetterModelClass(): string
    {
        $class = config('talkto.models.dead_letter', TalktoDeadLetter::class);

        return is_string($class) && is_a($class, TalktoDeadLetter::class, true)
            ? $class
            : TalktoDeadLetter::class;
    }
}
