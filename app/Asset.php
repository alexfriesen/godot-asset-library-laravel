<?php

declare(strict_types=1);

namespace App;

use GuzzleHttp\Client;
use GrahamCampbell\Markdown\Facades\Markdown;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;
use Lorisleiva\LaravelSearchString\Concerns\SearchString;

/**
 * An asset published by an user (called "author" in this case).
 *
 * @property int $asset_id The asset's unique ID.
 * @property string $title The asset's title.
 * @property ?string $blurb The asset's blurb (short description).
 * @property int $category_id The asset's category ID (see the `CATEGORY_*` constants).
 * @property-read string $category The asset's category as an human-readable name.
 * @property-read string $category_icon The asset's category Fork Awesome icon class.
 * @property string $cost The asset's license as an SPDX identifier.
 * @property-read string $license_name The asset's license as human-readable name.
 * @property int $support_level_id The asset's support level (see the `SUPPORT_LEVEL_*` constants).
 * @property-read string $support_level The asset's support level as an human-readable name.
 * @property string $description The asset's full description in Markdown format.
 * @property string $html_description The asset's full description in HTML format (generated from the Markdown source).
 * @property array $tags The asset's tags.
 * @property string $browse_url The asset's browsable repository URL.
 * @property string $issues_url The asset's issue reporting URL (will be inferred from `$browse_url` if empty).
 * @property ?string $changelog_url The asset's changelog URL.
 * @property ?string $donate_url The asset's donate URL.
 * @property string $icon_url The asset's icon URL (will be inferred from `$browse_url` if empty).
 * @property-read string $download_url The asset's latest version download URL (for compatibility with the existing asset library API).
 * @property-read string $version_string The asset's latest version's supported Godot minor version (e.g. "0.4.2", for compatibility with the existing asset library API).
 * @property string $godot_version The asset's latest version's human-readable identifier (e.g. "3.2", for compatibility with the existing asset library API).
 * @property \Illuminate\Support\Carbon $created_at The asset's creation date.
 * @property \Illuminate\Support\Carbon $modify_date The asset's last modification date.
 * @property bool $is_published If `false`, the asset can only be viewed by its author and won't appear in the list and search results.
 * @property bool $is_archived If `true`, the asset can no longer receive reviews.
 * @property int $score The score calculated from reviews (+1 for positive, -1 for negative).
 * @property-read string $score_color The Tailwind CSS classes used to color the asset's score in templates.
 * @property int $author_id The asset's author ID.
 */
class Asset extends Model
{
    use HasFactory;
    use SearchString;

    /**
     * The key used to store the last modification date and time.
     * This value has been changed from the default for compatibility with the
     * existing asset library API.
     */
    public const UPDATED_AT = 'modify_date';

    /**
     * The number of assets per page to display by default.
     */
    public const ASSETS_PER_PAGE = 40;

    /**
     * The maximum number of tags an asset may have.
     */
    public const MAX_TAGS = 15;

    /**
     * The support level voluntarily set by users who are submitting assets
     * in "testing" version (can be unstable).
     * Will be distinguished on the Web interface.
     */
    public const SUPPORT_LEVEL_TESTING = 0;

    /**
     * The default support level for newly submitted assets.
     */
    public const SUPPORT_LEVEL_COMMUNITY = 1;

    /**
     * The support level for assets submitted on behalf of the Godot project.
     * Will be distinguished on the Web interface.
     */
    public const SUPPORT_LEVEL_OFFICIAL = 2;

    public const SUPPORT_LEVEL_MAX = 3;

    public const CATEGORY_2D_TOOLS = 0;
    public const CATEGORY_3D_TOOLS = 1;
    public const CATEGORY_SHADERS = 2;
    public const CATEGORY_MATERIALS = 3;
    public const CATEGORY_TOOLS = 4;
    public const CATEGORY_SCRIPTS = 5;
    public const CATEGORY_MISC = 6;
    public const CATEGORY_TEMPLATES = 7;
    public const CATEGORY_PROJECTS = 8;
    public const CATEGORY_DEMOS = 9;
    public const CATEGORY_MAX = 10;

    /**
     * Assets that are part of the Add-ons category type will be displayed
     * in the editor's AssetLib tab.
     */
    public const CATEGORY_TYPE_ADDONS = 0;

    /**
     * Assets that are part of the Projects category type will be displayed
     * in the Project Manager's Templates tab.
     */
    public const CATEGORY_TYPE_PROJECTS = 1;

    /**
     * The mapping of the available licenses' SPDX identifiers with their human-readable names.
     * Should be kept in alphabetical order.
     */
    public const LICENSES = [
        'AGPL-3.0-only' => 'AGPLv3 only',
        'AGPL-3.0-or-later' => 'AGPLv3 or later',
        'Apache-2.0' => 'Apache 2',
        'BSD-2-Clause' => 'BSD 2-Clause',
        'BSD-3-Clause' => 'BSD 3-Clause',
        'BSL-1.0' => 'Boost Software License',
        'CC0-1.0' => 'CC0 1.0 Universal',
        'CC-BY-3.0' => 'CC BY 3.0 Unported',
        'CC-BY-4.0' => 'CC BY 4.0 International',
        'CC-BY-SA-3.0' => 'CC BY-SA 3.0 Unported',
        'CC-BY-SA-4.0' => 'CC BY-SA 4.0 International',
        'LGPL-2.1-only' => 'LGPLv2.1 only',
        'LGPL-2.1-or-later' => 'LGPLv2.1 or later',
        'LGPL-3.0-only' => 'LGPLv3 only',
        'LGPL-3.0-or-later' => 'LGPLv3 or later',
        'GPL-2.0-only' => 'GPLv2 only',
        'GPL-2.0-or-later' => 'GPLv2 or later',
        'GPL-3.0-only' => 'GPLv3 only',
        'GPL-3.0-or-later' => 'GPLv3 or later',
        'MIT' => 'MIT',
        'MPL-2.0' => 'MPLv2',
        'Unlicense' => 'The Unlicense License',
    ];

    public const CACHEKEY_REPO_ICON = 'ASSET_ICON_REPO';

    /**
     * The primary key associated with the table.
     * This value has been changed from the default for compatibility with the
     * existing asset library API.
     *
     * @var string
     */
    protected $primaryKey = 'asset_id';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'title',
        'blurb',
        'description',
        'tags',
        'author_id',
        'category_id',
        'cost',
        'support_level',
        'browse_url',
        'issues_url',
        'changelog_url',
        'donate_url',
        'icon_url',
        'versions',
        'previews',
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'support_level_id',
        'created_at',
         // The Godot editor can't render HTML, no need to send it
        'html_description',
        'is_published',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $appends = [
        'category',
        'tags',
        'download_hash',
        'download_url',
        'godot_version',
        'icon_url',
        'support_level',
        'version_string',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'category_id' => 'integer',
        'support_level_id' => 'integer',
        'is_published' => 'boolean',
        'is_archived' => 'boolean',
    ];

    /**
     * The columns that can be searched for using the search string syntax.
     *
     * @var array
     * @see https://github.com/lorisleiva/laravel-search-string#configuring-columns
     */
    protected $searchStringColumns = [
        'title' => ['searchable' => true],
        'blurb' => ['searchable' => true],
        'cost' => 'license',
        'support_level_id',
        'tags' => ['searchable' => true],
        'created_at',
        'modify_date' => 'updated_at',
        'score',
    ];

    /**
     * The special keywords allowed in the search string syntax.
     *
     * @var array<string, bool>
     * @see https://github.com/lorisleiva/laravel-search-string#configuring-special-keywords
     */
    protected $searchStringKeywords = [
        // Don't allow restricting the fields returned as it results in SQL errors
        'select' => false,
        // Don't allow sorting as it results in SQL errors when using a non-existing field
        'order_by' => false,
        // Don't allow offsetting as it results in SQL errors if not using `limit` at the same time
        'offset' => false,
    ];

    /**
     * Get the user that posted the asset.
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo('App\User', 'author_id');
    }

    /**
     * Get the asset's previews.
     */
    public function previews(): HasMany
    {
        return $this->hasMany('App\AssetPreview', 'asset_id');
    }

    /**
     * Get the asset's versions.
     */
    public function versions(): HasMany
    {
        return $this->hasMany('App\AssetVersion', 'asset_id');
    }

    /**
     * Get the asset's reviews (sorted by reverse creation date).
     */
    public function reviews(): HasMany
    {
        return $this->hasMany('App\AssetReview', 'asset_id')->orderBy('created_at', 'desc');
    }

    /**
     * Return the given support level's name.
     */
    public static function getSupportLevelName(int $supportLevel): string
    {
        $supportLevelNames = [
            self::SUPPORT_LEVEL_TESTING => 'Testing',
            self::SUPPORT_LEVEL_COMMUNITY => 'Community',
            self::SUPPORT_LEVEL_OFFICIAL => 'Official',
        ];

        if (array_key_exists($supportLevel, $supportLevelNames)) {
            return $supportLevelNames[$supportLevel];
        } else {
            throw new \Exception("Invalid support level: $supportLevel");
        }
    }

    /**
     * Return the given category's name.
     */
    public static function getCategoryName(int $category): string
    {
        $categoryNames = [
            self::CATEGORY_2D_TOOLS => '2D Tools',
            self::CATEGORY_3D_TOOLS => '3D Tools',
            self::CATEGORY_SHADERS => 'Shaders',
            self::CATEGORY_MATERIALS => 'Materials',
            self::CATEGORY_TOOLS => 'Tools',
            self::CATEGORY_SCRIPTS => 'Scripts',
            self::CATEGORY_MISC => 'Misc',
            self::CATEGORY_TEMPLATES => 'Templates',
            self::CATEGORY_PROJECTS => 'Projects',
            self::CATEGORY_DEMOS => 'Demos',
        ];

        if (array_key_exists($category, $categoryNames)) {
            return $categoryNames[$category];
        } else {
            throw new \Exception("Invalid category: $category");
        }
    }

    /**
     * Return the given category's Fork Awesome icon code.
     */
    public static function getCategoryIcon(int $category): string
    {
        $categoryNames = [
            self::CATEGORY_2D_TOOLS => 'fa-picture-o',
            self::CATEGORY_3D_TOOLS => 'fa-cube',
            self::CATEGORY_SHADERS => 'fa-book',
            self::CATEGORY_MATERIALS => 'fa-archive',
            self::CATEGORY_TOOLS => 'fa-cogs',
            self::CATEGORY_SCRIPTS => 'fa-file-text',
            self::CATEGORY_MISC => 'fa-gamepad',
            self::CATEGORY_TEMPLATES => 'fa-folder-open',
            self::CATEGORY_PROJECTS => 'fa-folder-open',
            self::CATEGORY_DEMOS => 'fa-folder-open',
        ];

        if (array_key_exists($category, $categoryNames)) {
            return $categoryNames[$category];
        } else {
            throw new \Exception("Invalid category: $category");
        }
    }

    /**
     * Return the list of tags as an array.
     *
     * @return string[]
     */
    public function getTagsAttribute(): array
    {
        if (! empty($this->getRawOriginal('tags'))) {
            return explode(',', $this->getRawOriginal('tags'));
        }

        return [];
    }

    /**
     * Set the list of tags (which is stored as a comma-separated string in the database).
     *
     * TODO: Use a custom validator to enforce tag naming rules.
     *
     * @param string|array $tags The list of tags to assign to the asset
     */
    public function setTagsAttribute($tags = null): void
    {
        if (is_array($tags)) {
            $this->attributes['tags'] = implode(',', $tags);
        } else {
            // Remove empty tags, spaces and replace uppercase characters with lowercase characters
            $tagsSanitized = array_filter(
                explode(',', strtolower(str_replace(' ', '', $tags ?? ''))),
                function ($tag) {
                    return strlen($tag) >= 1;
                }
            );

            $this->attributes['tags'] = implode(',', $tagsSanitized);
        }
    }

    /**
     * Enforces HTTPS for the repository URL.
     * This also makes sure the repository URL doesn't end with `.git` or a trailing slash.
     */
    public function setBrowseUrlAttribute(string $browseUrl): void
    {
        $httpsUrl = str_replace('http://', 'https://', $browseUrl);

        if (Str::endsWith($httpsUrl, '.git')) {
            $this->attributes['browse_url'] = str_replace('.git', '', $httpsUrl);
        } else {
            $this->attributes['browse_url'] = rtrim($httpsUrl, '/');
        }
    }

    /**
     * Placeholder to make the Godot editor accept the download hash but not verify it.
     *
     * TODO: Consider computing the download hash on a per-version basis and store it.
     *       However, this may not be a good idea as GitHub ZIP hashes aren't guaranteed
     *       to be the same over a long period of time.
     */
    public function getDownloadHashAttribute(): string
    {
        return '';
    }

    /**
     * Return the download URL corresponding to the latest version
     * (for compatibility with the existing API).
     */
    public function getDownloadUrlAttribute(): string
    {
        return $this->versions->last()->getDownloadUrlAttribute($this->browse_url);
    }

    /**
     * Return the Godot version corresponding to the latest version
     * (for compatibility with the existing API).
     */
    public function getGodotVersionAttribute(): string
    {
        return $this->versions->last()->godot_version;
    }

    /**
     * Set the description, render the Markdown and save the rendered description.
     * This way, the source Markdown only has to be rendered once
     * (instead of being rendered every time a page is displayed).
     */
    public function setDescriptionAttribute(string $description = null): void
    {
        if ($description) {
            $this->attributes['description'] = $description;
            $this->attributes['html_description'] = Markdown::convert($description)->getContent();
        }
    }

    /**
     * Return the icon URL (will infer a URL if no custom URL is specified by the asset).
     */
    public function getIconUrlAttribute(): string
    {
        // Return the custom icon URL if defined
        if ($this->getRawOriginal('icon_url')) {
            return $this->getRawOriginal('icon_url');
        }

        $gitIcon = $this->findGitIcon($this->browse_url);
        if ($gitIcon) {
            return $gitIcon;
        }

        // Couldn't infer an icon URL
        return '';
    }

    /**
     * Enforces HTTPS for the icon URL.
     */
    public function setIconUrlAttribute(string $iconUrl = null): void
    {
        // This field is nullable, so we check for the parameter first
        if ($iconUrl) {
            $this->attributes['icon_url'] = str_replace('http://', 'https://', $iconUrl);
        }
    }

    /**
     * Return the issue reporting URL (will infer a URL if no custom URL is specified by the asset).
     */
    public function getIssuesUrlAttribute(): string
    {
        // GitHub, GitLab and Bitbucket all use an `/issues` suffix
        return $this->getRawOriginal('issues_url') ?? "$this->browse_url/issues";
    }

    /**
     * Enforces HTTPS for the issue reporting URL.
     */
    public function setIssuesUrlAttribute(string $issuesUrl = null): void
    {
        // This field is nullable, so we check for the parameter first
        if ($issuesUrl) {
            $this->attributes['issues_url'] = str_replace('http://', 'https://', $issuesUrl);
        }
    }

    /**
     * Return the asset version corresponding to the latest version
     * (for compatibility with the existing API).
     */
    public function getVersionStringAttribute(): string
    {
        return $this->versions->last()->version_string;
    }

    /**
     * Non-static variant of `getCategoryName()` (used in serialization).
     */
    public function getCategoryAttribute(): string
    {
        return self::getCategoryName($this->category_id);
    }

    /**
     * Non-static variant of `getCategoryIcon()` (used in templates).
     */
    public function getCategoryIconAttribute(): string
    {
        return self::getCategoryIcon($this->category_id);
    }

    /**
     * Non-static variant of `getSupportLevelName()` (used in serialization).
     */
    public function getSupportLevelAttribute(): string
    {
        return self::getSupportLevelName($this->support_level_id);
    }

    /**
     * Non-static variant of `getLicenseName` (used in templates).
     */
    public function getLicenseNameAttribute(): string
    {
        // Return the SPDX identifier as a fallback
        return self::LICENSES[$this->cost] ?? $this->cost;
    }

    /**
     * Return the given category's type.
     */
    public static function getCategoryType(int $category): int
    {
        switch ($category) {
            case self::CATEGORY_TEMPLATES:
            case self::CATEGORY_PROJECTS:
            case self::CATEGORY_DEMOS:
                return self::CATEGORY_TYPE_PROJECTS;
            default:
                if ($category >= 0 && $category < self::CATEGORY_MAX) {
                    return self::CATEGORY_TYPE_ADDONS;
                }

                throw new \Exception("Invalid category: $category");
        }
    }

    /**
     * Return the Tailwind CSS class used to color the score displayed.
     * Higher scores will be displayed in a color with a warmer temperature
     * to attract the user's attention.
     *
     * @see https://tailwindcss.com/docs/text-color/ Tailwind's color classes.
     */
    public function getScoreColorAttribute(): string
    {
        if ($this->score >= 15) {
            return 'text-blue-500 dark:text-blue-400';
        } elseif ($this->score >= 10) {
            return 'text-blue-600 dark:text-blue-300';
        } elseif ($this->score >= 5) {
            return 'text-blue-700 dark:text-blue-200';
        } elseif ($this->score >= -1) {
            return 'text-gray-700 dark:text-gray-500';
        } else {
            return 'text-red-700 dark:text-red-400';
        }
    }

    /**
     * Filter and sort the list of assets according to user-specified parameters.
     * This function doesn't perform any validation. The data passed must be
     * validated first!
     *
     * TODO: Implement more search filters.
     *
     * @see App\Http\Requests\ListAssets
     */
    public function scopeFilterSearch(Builder $query, array $validated, bool $publishedOnly = true): Collection
    {
        if ($publishedOnly) {
            $query->where('is_published', true);
        }

        if (isset($validated['type'])) {
            // FIXME: Avoid duplicating the category type detection
            switch ($validated['type']) {
                case 'addon':
                    $query->whereNotIn(
                        'category_id',
                        [self::CATEGORY_TEMPLATES, self::CATEGORY_PROJECTS, self::CATEGORY_DEMOS]
                    );
                    break;
                case 'project':
                    $query->whereIn(
                        'category_id',
                        [self::CATEGORY_TEMPLATES, self::CATEGORY_PROJECTS, self::CATEGORY_DEMOS]
                    );
                    break;
                default:
                    break;
            }
        }

        if (isset($validated['category'])) {
            $query->where('category_id', $validated['category']);
        }

        if (isset($validated['user'])) {
            // Prevent the value from being null, assign `-1` if the user isn't found
            // (no user ID will match it, as IDs are always positive)
            $queryAuthorId = User::where('name', $validated['user'])->first()['id'] ?? -1;
            $query->where('author_id', $queryAuthorId);
        }

        if (isset($validated['filter'])) {
            // Search string options are defined in the Asset model
            $query->usingSearchString($validated['filter']);
        }

        $reverse = isset($validated['reverse']);

        if (isset($validated['sort'])) {
            // Only valid `sort` options affect the sort order in any way
            switch ($validated['sort']) {
                case 'cost':
                    $query->orderBy('cost', $reverse ? 'desc' : 'asc');
                    break;
                case 'name':
                    $query->orderBy('title', $reverse ? 'desc' : 'asc');
                    break;
                case 'rating':
                    // Having the best ratings first by default makes more sense
                    $query->orderBy('score', $reverse ? 'asc' : 'desc');
                    break;
                default:
                    // Also handles `updated`
                    $query->orderBy('modify_date', $reverse ? 'asc' : 'desc');
                    break;
            }
        } else {
            // Sort by update date by default
            $query->orderBy('modify_date', $reverse ? 'asc' : 'desc');
        }

        // Query builder methods must be called above, not below

        $result = $query->get();

        if (isset($validated['godot_version'])) {
            // Filtering on a computed property can't be done with the query builder,
            // so we have to do it on the returned collection instead.
            // See `AssetVersion::GODOT_VERSION_FILTERS` for the list of allowed version filters.

            // Only return results compatible with the given Godot version filter.
            // Assets whose Godot version is `*` are declared to be compatible with
            // any Godot version (this is typically used for non-code assets).
            $result = $result->filter(function ($asset) use ($validated) {
                return $asset->godot_version === '*'
                || $asset->godot_version === '3.x.x' && Str::startsWith($validated['godot_version'], '3')
                || $asset->godot_version === '4.x.x' && Str::startsWith($validated['godot_version'], '4')
                || $asset->godot_version === $validated['godot_version'].'.x';
            });
        }

        return $result;
    }

    /**
     * Converts the model to a string representation (used for logging purposes).
     */
    public function __toString(): string
    {
        return "\"$this->title\" (#$this->asset_id)";
    }

    /**
     * Tries to find a icon.png in the git repository.
     */
    private function findGitIcon(string $repoUrl): string | null
    {
        $cacheKey = $this->CACHEKEY_REPO_ICON.'-'.$this->asset_id;
        $cached = Cache::get($cacheKey);
        if ($cached) {
            debug('icon from cache!');
            return $cached;
        }

        $splitUrl = explode('/', $repoUrl);

        // Slug of the form `user/repository`
        $slug = "$splitUrl[3]/$splitUrl[4]";

        $repoIcon = null;

        // Try to infer an icon URL based on the repository host
        // (`icon.png` at the repository root)
        if ($splitUrl[2] === 'github.com') {
            $repoIcon = $this->getExistingUrl([
                "https://raw.githubusercontent.com/$slug/main/icon.png",
                "https://raw.githubusercontent.com/$slug/master/icon.png",
            ]);
        } elseif ($splitUrl[2] === 'gitlab.com') {
            $repoIcon = $this->getExistingUrl([
                "https://gitlab.com/$slug/raw/main/icon.png",
                "https://gitlab.com/$slug/raw/master/icon.png",
            ]);
        } elseif ($splitUrl[2] === 'bitbucket.org') {
            $repoIcon = $this->getExistingUrl([
                "https://bitbucket.org/$slug/raw/main/icon.png",
                "https://bitbucket.org/$slug/raw/master/icon.png",
            ]);
        }

        if($repoIcon) {
            // cache the icon for 15min
            Cache::put($cacheKey, $repoIcon, 900);
            return $repoIcon;
        }

        return null;
    }

    private function getExistingUrl(array $urls): string | null {
        $client = new Client(['timeout'  => 0.2, 'http_errors' => false]);
        foreach ($urls as $url) {
            try {
                if ($client->head($url)->getStatusCode() == '200') {
                    return $url;
                }
            } catch(\Exception $error) {
                return null;
            }
        }

        return null;
    }
}
