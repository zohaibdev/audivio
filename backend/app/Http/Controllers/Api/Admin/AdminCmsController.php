<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CmsPageRequest;
use App\Http\Requests\Admin\CmsSectionRequest;
use App\Http\Responses\ApiResponse;
use App\Models\CmsPage;
use App\Models\CmsSection;
use Illuminate\Http\JsonResponse;

class AdminCmsController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success(CmsPage::withCount('sections')->orderBy('slug')->get());
    }

    public function store(CmsPageRequest $request): JsonResponse
    {
        $page = CmsPage::create($request->validated());

        return ApiResponse::success($page, status: 201);
    }

    public function show(CmsPage $page): JsonResponse
    {
        return ApiResponse::success($page->load('sections'));
    }

    public function update(CmsPageRequest $request, CmsPage $page): JsonResponse
    {
        $page->update($request->validated());

        return ApiResponse::success($page->fresh());
    }

    public function destroy(CmsPage $page): JsonResponse
    {
        $page->delete();

        return ApiResponse::success(message: 'Page deleted.');
    }

    /**
     * Upserts a section by key — the frontend calls this to both create a
     * new named section and edit an existing one, without needing to know
     * which case it is.
     */
    public function putSection(CmsSectionRequest $request, CmsPage $page, string $sectionKey): JsonResponse
    {
        $section = CmsSection::updateOrCreate(
            ['cms_page_id' => $page->id, 'section_key' => $sectionKey],
            $request->validated(),
        );

        return ApiResponse::success($section);
    }

    public function destroySection(CmsPage $page, string $sectionKey): JsonResponse
    {
        $deleted = CmsSection::where('cms_page_id', $page->id)->where('section_key', $sectionKey)->delete();

        if ($deleted === 0) {
            return ApiResponse::error('Section not found.', 404);
        }

        return ApiResponse::success(message: 'Section deleted.');
    }
}
