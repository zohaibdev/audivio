<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Website;
use App\Models\WebsiteAioPrompt;
use App\Services\Audit\AioPromptLibrary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Custom AIO prompts for one website (§42).
 *
 * Every lookup starts from `$request->user()->websites()`, the same rule
 * as WebsiteController: the id in the path selects which of *your*
 * websites, and the scoping is what authorizes it.
 */
class WebsiteAioPromptController extends Controller
{
    public function __construct(private readonly AioPromptLibrary $library) {}

    public function index(Request $request, string $website): JsonResponse
    {
        $model = $this->findForUser($request, $website);

        if ($model === null) {
            return ApiResponse::error('Website not found.', 404);
        }

        return ApiResponse::success([
            'prompts' => $model->aioPrompts()->orderBy('sort_order')->orderBy('id')->get()
                ->map(fn (WebsiteAioPrompt $p) => [
                    'id' => $p->id,
                    'prompt' => $p->prompt,
                    'sort_order' => $p->sort_order,
                ]),
            // The defaults are shown too, so the UI can present "10 checks
            // always run, plus yours" rather than implying an empty list
            // means nothing is being asked.
            'defaults' => collect(config('aio_prompts.defaults'))
                ->map(fn (array $p) => ['key' => $p['key'], 'question' => $p['question'], 'needs_context' => $p['needs_context']])
                ->values(),
            'limit' => $this->library->customPromptLimit($model),
            'can_add' => $this->library->canAddCustomPrompt($model),
            'context' => $this->library->skippedFor($model),
        ]);
    }

    public function store(Request $request, string $website): JsonResponse
    {
        $model = $this->findForUser($request, $website);

        if ($model === null) {
            return ApiResponse::error('Website not found.', 404);
        }

        $validated = $request->validate([
            'prompt' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        // Checked at the point of adding, so the user is told now rather
        // than having the prompt silently dropped at audit time.
        if (! $this->library->canAddCustomPrompt($model)) {
            $limit = $this->library->customPromptLimit($model);

            return ApiResponse::error(
                $limit === 0
                    ? 'Custom AIO prompts are not included in your current plan.'
                    : "Your plan includes {$limit} custom AIO prompt".($limit === 1 ? '' : 's').'. Remove one, or upgrade, to add another.',
                403,
                ['limit' => 'custom_aio_prompt_limit'],
            );
        }

        $prompt = $model->aioPrompts()->create([
            'prompt' => trim($validated['prompt']),
            'sort_order' => (int) $model->aioPrompts()->max('sort_order') + 1,
        ]);

        return ApiResponse::success([
            'id' => $prompt->id,
            'prompt' => $prompt->prompt,
            'sort_order' => $prompt->sort_order,
        ], status: 201);
    }

    public function destroy(Request $request, string $website, int $prompt): JsonResponse
    {
        $model = $this->findForUser($request, $website);

        if ($model === null) {
            return ApiResponse::error('Website not found.', 404);
        }

        // Scoped to the website, so a prompt id from someone else's site
        // is simply not in the set being queried.
        $row = $model->aioPrompts()->whereKey($prompt)->first();

        if ($row === null) {
            return ApiResponse::error('Prompt not found.', 404);
        }

        $row->delete();

        return ApiResponse::success(message: 'Prompt removed.');
    }

    private function findForUser(Request $request, string $website): ?Website
    {
        $query = $request->user()->websites();

        if (ctype_digit($website)) {
            return $query->find((int) $website);
        }

        $segment = rtrim(strtolower(trim($website)), '.');
        $segment = str_starts_with($segment, 'www.') ? substr($segment, 4) : $segment;

        return $query->where('normalized_domain', $segment)->first();
    }
}
