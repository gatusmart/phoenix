<?php

namespace App\Http\Controllers\Backend\Spa\Content;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

use App\Models\Core\Content\ContentType;
use App\Models\Core\Content\Template;
use App\Models\Core\Content\TemplateBlock;
use App\Models\Core\Content\Content;
use App\Models\Core\Content\ContentBlock;
use App\Models\Core\Settings\Website;

use App\Models\Core\Settings\Setting;
use App\Models\Core\Taxonomies\Taxonomy;

use Artisan;
use DB;
use Session;
use Auth;
use Timezone;
use Carbon\Carbon;
use Jenssegers\Agent\Agent;

use App\Http\Resources\ContentResource;
use App\Http\Resources\ContentTypeResource;
use App\Http\Resources\ContentCollection;
use App\Http\Resources\SettingResource;

use App\Services\WebsiteService;
use App\Services\Themes\ThemeService;

class ContentController extends Controller
{
    protected $websiteService;
    protected $themeservice;

    public function __construct(WebsiteService $websiteService, ThemeService $themeservice)
	{
        $this->websiteService = $websiteService;
        $this->themeservice = $themeservice;
	}

    public function index(Request $request, $contentTypeId)
    {
        $search = $request->search;
        $filter = $request->filter;
        $status = $request->status;
        $page = $request->page;
        $perPage = isset($request->per_page) ? $request->per_page : 12;
        $sort = $request->sort;

        $content = Content::with('terms')->with('author')->with('featuredimage')->whereContentTypeId($contentTypeId);

        if($sort == 'latest')
            $content = $content->latest();
        else
            $content = $content->oldest();

        if($search) {
            switch ($filter) {
                case 'username':
                    $content->whereHas('author', function($query) use($search) {
                        $query->where('firstname', 'LIKE', '%'. $search . '%');
                    });
                break;

                case 'title':
                    $content->where('title', 'LIKE', '%'. $search . '%');
                break;

                default:
                    # code...
                break;
            }
        }

        $counts = Content::whereContentTypeId($contentTypeId)
            ->selectRaw('COUNT(*) as allCount,
                         SUM(status='.Content::PUBLISH.') as publishedCount,
                         SUM(status='.Content::DRAFT.') as draftCount,
                         SUM(status='.Content::SCHEDULE.') as scheduledCount')
            ->first();

        return (new ContentCollection($content->paginate($perPage)))
            ->additional([
                'counts' => $counts,
                // 'contentTypeData' => new ContentTypeResource($contentType)
            ]);
    }

    public function getInitData(Request $request, $contentTypeId)
    {
        $contentType = ContentType::whereId($contentTypeId)->first();
        $contentTaxonomies = $contentType->taxonomies->each->setAppends(['terms', 'settings']);
        
        $websiteSettings = $this->websiteService->getSettings();
        $activeThemeId = data_get($websiteSettings, 'website.activeTheme');
        $editorSettings = data_get($websiteSettings, 'contentEditor');

        $themeSettings = $this->themeservice->getSettings($activeThemeId);
        $defaultContentLayout = data_get($themeSettings, 'content.' . lcfirst($contentType->slug) .'.layout');
        $themeContentSettings = data_get($themeSettings, 'content.' . lcfirst($contentType->slug));

        return response()->json(compact('contentTaxonomies', 'editorSettings', 'defaultContentLayout', 'themeContentSettings'));
    }

    public function show(Request $request, $contentTypeId, $contentId)
    {
        $content = Content::with('blocks')->with('terms')->with('author')->with('featuredimage')->find($contentId);
        $contentType = ContentType::whereId($contentTypeId)->first();
        $contentTaxonomies = $contentType->taxonomies->each->setAppends(['terms', 'settings']);
        
        $websiteSettings = $this->websiteService->getSettings();
        $activeThemeId = data_get($websiteSettings, 'website.activeTheme');
        $editorSettings = data_get($websiteSettings, 'contentEditor');

        $themeSettings = $this->themeservice->getSettings($activeThemeId);
        $themeContentSettings = data_get($themeSettings, 'content.' . lcfirst($contentType->slug));

        // merge content settings with global theme settings
        $content->settings = $content->settings ? array_merge($themeContentSettings->toArray(), $content->settings) : $themeContentSettings->toArray();

        $defaultContentLayout = data_get($themeSettings, 'content.' . lcfirst($contentType->slug) . '.layout');

        return (new ContentResource($content))->additional(compact('themeContentSettings', 'contentTaxonomies', 'editorSettings', 'defaultContentLayout'));
    }

    public function store(Request $request, $contentTypeId)
    {
        $content = $this->save($request, $contentTypeId);
        return new ContentResource($content);
    }

    public function update(Request $request, $contentTypeId, $contentId)
    {
        $content = $this->save($request, $contentTypeId, $contentId);
        return new ContentResource($content);
    }

    protected function save($request, $contentTypeId, $id = null)
    {
        $contentType = ContentType::whereId($contentTypeId)->first();
        $websiteSettings = $this->websiteService->getSettings();
        $activeThemeId = data_get($websiteSettings, 'website.activeTheme');
        $themeSettings = $this->themeservice->getSettings($activeThemeId);

        // delete user removed blocks
        // $block = ContentBlock::whereIn('unique_id', $request->removedItems)->delete();
        foreach ($request->removedItems as $key => $itemId) {
            $block = ContentBlock::where('unique_id', $itemId)->first();
            $block ? $block->delete() : null;
        }

        $content = Content::firstOrNew(['id' => $id]);
        $content->content_type_id = $contentType->id;
        $content->title = $request->title;
        $content->seo = $request->seo;
        $content->layout = $request->layout == 'default' ? null : $request->layout;
        $content->status = $request->status;
        // $content->published_at = $request->publishAt;
        $content->user_id = Auth::user()->id;
        $content->slug = $content->title;
        $content->css = $request->css;
        $content->js = $request->js;

        $themeContentSettings = data_get($themeSettings, 'content.' . lcfirst($contentType->slug));
        $settings = array_diff($request->settings, $themeContentSettings->toArray());
        $content->settings = !empty($settings) ? $settings : null;
        $content->save();
        $content->touch();

        $content->setTaxonomies($request->taxonomiesData);

        foreach($request->blocksData as $key => $blockData) {
            $blockData = (object) $blockData;
            $this->saveBlock($content->id, $blockData);
        }

        $content = Content::with('blocks')->where('id', $content->id)->first();

        if($content) {
            // Artisan::call('page-cache:clear', ['slug' => $content->slug]);
            // return new ContentResource($content);
        }

        // $content->save();

        return $content;
    }

    public function destroy($contentTypeId, $contentId)
    {
        $content = Content::find($contentId);

        if($content->delete()) {
            return response()->json(null, 204);
        } else {
            return response()->json([
                'message' => 'Could not delete.'
            ], 403);
        }
    }

    public function setFeaturedImage(Request $request)
    {
        $content = Content::find($request->id);
        $content->featured_image_id = $request->featuredImageId;
        $content->save();
        return response()->json([
            'status' => 'success'
        ], 200);
    }

    public function removeFeaturedImage(Request $request)
    {
        $content = Content::find($request->id);
        $content->featured_image_id = null;
        $content->save();
        return response()->json([
            'status' => 'success'
        ], 200);
    }

    protected function saveBlock($contentId, $blockData, $parentId = null)
    {
        $blockData = (object) $blockData;
        // create or update the block in database
        $block = ContentBlock::set($contentId, $blockData, $parentId);

        // create or update block settings
        foreach ($block->getSettings() as $baseKey => $value) {
            if(!array_key_exists($baseKey, $blockData->settings)) {
                $block->removeSetting($block->type, $baseKey);
            }
        }

        // only save settings that have been customized by user
        foreach ($blockData->settings as $key => $setting) {
            $block->setSetting($key, $setting['value'], $setting['type'], $blockData->type);
        }


        // process sub blocks recursivly
        if(isset($blockData->subItems)) {
            for ($i=0; $i < count($blockData->subItems); $i++) {
                $this->saveBlock($contentId, $blockData->subItems[$i], $block->unique_id);
            }
        }
    }

    protected function processBlocks($rawBlocks)
    {
        foreach ($rawBlocks as $key => $block) {
            $title = $block->settings;
            foreach ($block->settings as $key => $setting) {
                if($setting['key'] == 'order') {
                    $block->order = (int)$setting['value'];
                    break;
                }
            }
        }

        // sort the blocks
        $rawBlocks = $rawBlocks->sortBy('order');

        // reset keys
        $blocks = [];
        foreach ($rawBlocks as $key => $block) {
            if($this->isJson($block['content']))
                $block['content'] = json_decode($block['content'], true);

            array_push($blocks, $block);
        }

        return $blocks;
    }

    function isJson($string) {
        json_decode($string);
        return (json_last_error() == JSON_ERROR_NONE);
    }
}
