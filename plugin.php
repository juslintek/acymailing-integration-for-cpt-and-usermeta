<?php

use AcyMailing\Helpers\PluginHelper;
use AcyMailing\Helpers\TabHelper;
use AcyMailing\Libraries\acymPlugin;
use App\Constant\AnnouncementStatus;
use Illuminate\Support\Carbon;

class plgAcymCptandusermeta extends acymPlugin
{
    public function __construct()
    {
        parent::__construct();
        $this->cms = 'WordPress';
        $this->rootCategoryId = 0;

        $this->pluginDescription->name = 'CPT and Usermeta';
        $this->pluginDescription->icon = ACYM_PLUGINS_URL . '/' . basename(__DIR__) . '/icon.png';
        $this->pluginDescription->image = ACYM_PLUGINS_URL . '/' . basename(__DIR__) . '/banner.png';
        $this->pluginDescription->category = 'Content management';
        $this->pluginDescription->features = '["content","automation"]';
        $this->pluginDescription->description = '- Insert custom post types and filter them by usermeta in your emails<br />- Filter posts by postmeta';

        if ($this->installed && ACYM_CMS === 'wordpress') {
            $this->initCustomView();

            $this->settings = [
                'custom_view' => [
                    'type' => 'custom_view',
                    'tags' => array_merge($this->displayOptions, $this->replaceOptions, $this->elementOptions),
                ],
            ];
        }
    }

    public function initReplaceOptionsCustomView()
    {
        $this->replaceOptions = [
            'link' => ['ACYM_LINK'],
            'picthtml' => ['ACYM_IMAGE'],
            'readmore' => ['ACYM_READ_MORE'],
        ];
    }

    // Not sure what this for at all, do I even need it, it throws an error when it is missing
    public function initElementOptionsCustomView(): void
    {
    }

    public function getPossibleIntegrations()
    {
        return $this->pluginDescription;
    }

    public function insertionOptions($defaultValues = null)
    {
        $this->defaultValues = $defaultValues ?? $this->getDefaultValues();

        $tabHelper = new TabHelper();
        $identifier = $this->name;

        $displayOptions = [
            [
                'title' => 'ACYM_USER_FAVORITES',
                'type' => 'boolean',
                'name' => 'favorites',
                'default' => true,
            ],
        ];

        $catOptions = [
            [
                'title' => 'ACYM_ORDER_BY',
                'type' => 'select',
                'name' => 'order',
                'options' => [
                    'ID' => 'ACYM_ID',
                    'post_date' => 'ACYM_PUBLISHING_DATE',
                    'post_modified' => 'ACYM_MODIFICATION_DATE',
                    'post_title' => 'ACYM_TITLE',
                    'menu_order' => 'ACYM_MENU_ORDER',
                    'rand' => 'ACYM_RANDOM',
                ],
            ],
            [
                'title' => 'ACYM_MIN_PUBLISH_DATE',
                'tooltip' => 'ACYM_MIN_PUBLISH_DATE_DESC',
                'type' => 'date',
                'name' => 'min_publish',
                'default' => '',
                'relativeDate' => '-',
            ]
        ];

        $this->autoCampaignOptions($catOptions, true);

        $displayOptions = array_merge($displayOptions, $catOptions);

        $tabHelper->startTab(__('Skelbimai', 'mnm'), !empty($this->defaultValues->defaultPluginTab) && $identifier === $this->defaultValues->defaultPluginTab);

        echo $this->pluginHelper->displayOptions($displayOptions, $identifier, 'grouped', $this->defaultValues);

        $tabHelper->endTab();

        $tabHelper->display('plugin');
    }

    protected function getDefaultValues(): stdClass
    {
        $shortcode = acym_getVar('string', 'shortcode', '');

        $defaultValues = new stdClass();

        $shortcode = trim($shortcode, '{}');
        $separatorPosition = strpos($shortcode, ':');
        if (false !== $separatorPosition) {
            $pluginSubType = substr($shortcode, 0, $separatorPosition);
            $shortcode = substr($shortcode, $separatorPosition + 1);
            $pluginHelper = new PluginHelper();
            $defaultValues = $pluginHelper->extractTag($shortcode);
            $defaultValues->defaultPluginTab = $pluginSubType;
        }

        return $defaultValues;
    }

    /**
     * @throws Throwable
     */
    public function replaceUserInformation(&$email, &$user, $send): array
    {
        $tags = $this->pluginHelper->extractTags($email, $this->name);
        $this->tags = [];

        foreach ($tags as $oneTag => $parameter) {
            if (isset($this->tags[$oneTag])) {
                return [
                    'send' => true,
                    'message' => $oneTag . ' ' . acym_translation('ACYM_TAG_ALREADY_USED'),
                ];
            }

            $args = [
                'post_type' => 'announcement',
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'meta_query' => [
                    'relation' => 'AND',
                    [
                        'key' => 'status',
                        'value' => AnnouncementStatus::STATUS_ACTIVE,
                        'compare' => '=',
                    ]
                ],
            ];

            if (!empty($parameter->favorites)) {
                $favorites = $this->getUserFavorites($user->cms_id);
                if (empty($favorites['all'])) {
                    return [
                        'send' => false,
                        'message' => str_replace('{email}', $user->email, __('Klientas {email} neturi sekamų skelbimų', 'mnm')),
                    ];
                }

                $args['post__in'] = $favorites['all'];
            }


            if (!empty($parameter->min_publish)) {
                $min_publish = acym_date(acym_replaceDate($parameter->min_publish), 'Y-m-d H:i:s', false);
                $args['date_query'][] = [
                    'column' => 'post_date_gmt',
                    'after' => $min_publish,
                    'inclusive' => true,
                ];
            }

            if (!empty($parameter->datefilter) && $parameter->datefilter === 'onlynew') {
                $lastGenerated = $this->getLastGenerated($email->id);
                if (!empty($lastGenerated)) {
                    $args['date_query'][] = [
                        'column' => 'post_date_gmt',
                        'after' => date('Y-m-d H:i:s', $lastGenerated),
                        'inclusive' => false,
                    ];
                }
            }

            if (!empty($parameter->datefilter) && $parameter->datefilter === 'onlymodified') {
                $lastGenerated = $this->getLastGenerated($email->id);
                if (!empty($lastGenerated)) {
                    $args['date_query'][] = [
                        'column' => 'post_modified_gmt',
                        'after' => date('Y-m-d H:i:s', $lastGenerated),
                        'inclusive' => false,
                    ];
                }
            }

            if (!empty($parameter->order)) {
                [$column, $order] = explode(',', $parameter->order);
                if ($column === 'rand') {
                    $args['orderby'] = $column;
                } else {
                    $args['orderby'] = [$column => $order];
                }
            }

            if (!empty($parameter->max)) {
                $args['posts_per_page'] = $parameter->max;
            }

            $this->tags[$oneTag] = '';

            if (isset($favorites) && !empty($favorites['grouped'])) {
                if ($parameter->min >= count($favorites['all'])) {
                    return [
                        'send' => false,
                        'message' => str_replace('{email}', $user->email, __('Kliento {email} per mažas sekamų skelbimų kiekis', 'mnm')),
                    ];
                }

                $this->orderFavoritesByParameters($favorites, $parameter);

                $contentGrouper = [];
                foreach ($favorites['grouped'] as $groupName => $favoritesGroup) {
                    if (count($favoritesGroup) === 0) {
                        continue;
                    }

                    $contentGrouper[$groupName] = '<div class="grouped-block">';
                    $contentGrouper[$groupName] .= '<h1 class="group-name">' . $groupName . '</h1>';

                    $totalPosts = 0;
                    foreach ($favoritesGroup as $postId) {
                        $post = get_post($postId);
                        $data = get_announcement_post_data($post);
                        if ($post->post_status !== 'publish') {
                            continue;
                        }

                        if ($data['status_value'] === AnnouncementStatus::STATUS_EXPIRED) {
                            continue;
                        }

                        if (!empty($min_publish) && Carbon::parse($post->post_date_gmt)->isBefore(Carbon::parse($min_publish))) {
                            continue;
                        }

                        if (!empty($lastGenerated)
                            && $parameter->datefilter === 'onlynew'
                            && Carbon::parse($post->post_date_gmt)->isBefore(Carbon::createFromTimestamp($lastGenerated))) {
                            continue;
                        }

                        if (!empty($lastGenerated)
                            && $parameter->datefilter === 'onlymodified'
                            && Carbon::parse($post->post_modified_gmt)->isBefore(Carbon::createFromTimestamp($lastGenerated))) {
                            continue;
                        }

                        $changes = array_slice(get_announcement_post_data_historical_changes_formed($post), 0, 1);

                        if (empty($changes)) {
                            continue;
                        }

                        $contentGrouper[$groupName] .= \Roots\view('emails.announcement', [
                            'data' => $data,
                            'post_changes' => $changes,
                        ])->render();
                        $totalPosts++;
                    }

                    $contentGrouper[$groupName] .= '</div>';
                    if ($totalPosts === 0) {
                        unset($contentGrouper[$groupName]);
                    }
                }

                $this->tags[$oneTag] = implode('<div class="grouped-block-spacer"></div>', $contentGrouper);
            } else {
                $query = new WP_Query($args);

                if ($parameter->min > $query->post_count) {
                    return [
                        'send' => false,
                        'message' => str_replace('{email}', $user->email, __('Kliento {email} per mažas sekamų skelbimų kiekis', 'mnm')),
                    ];
                }

                foreach ($query->get_posts() as $post) {
                    $changes = array_slice(get_announcement_post_data_historical_changes_formed($post), 0, 1);

                    if (empty($changes)) {
                        continue;
                    }

                    $this->tags[$oneTag] .= \Roots\view('emails.announcement', [
                        'data' => get_announcement_post_data($post),
                        'post_changes' => $changes,
                    ])->render();
                }
            }
        }

        $this->pluginHelper->replaceTags($email, $this->tags, true);

        return [];
    }

    /**
     * @param $userId
     * @return array{all: int[], grouped: array<string, int[]>} Array of favorite post IDs, grouped by business.
     *                                                          Group key is name of the business or person.
     */
    private function getUserFavorites($userId): array
    {
        $favorites = get_user_meta($userId, 'simplefavorites');
        $favoritesByBusiness = [];
        if (empty($favorites)) {
            return $favoritesByBusiness;
        }

        $currentSiteId = is_multisite() ? get_current_site()->site_id : 1;
        $groupedFavorites = [];
        foreach ($favorites[0] as $siteFavorites) {
            if ($siteFavorites['site_id'] !== $currentSiteId) {
                continue;
            }

            $favoritesByBusiness['all'] = $siteFavorites['posts'];

            foreach ($siteFavorites['groups'] as $group) {
                if (empty($group['group_id']) || empty($group['posts'])) {
                    continue;
                }

                $entityType = get_post_meta($group['group_id'], 'entity_type', true);
                if ($entityType === 'person') {
                    $groupName = get_post_meta($group['group_id'], 'person_name', true)
                        . ' ' .
                        get_post_meta($group['group_id'], 'person_surname', true);
                }

                if ($entityType === 'company') {
                    $groupName = get_post_meta($group['group_id'], 'company_name', true);
                }

                if (empty($entityType)) {
                    $groupName = __('Asmeniškai sekami skelbimai', 'mnm');
                } else {
                    $groupedFavorites[] = $group['posts']; // Do not aggregate personal favorites as grouped favorites
                }

                if (!empty($favoritesByBusiness['grouped'][$groupName])) {
                    $favoritesByBusiness['grouped'][$groupName] = array_unique(
                        array_merge(
                            $favoritesByBusiness['grouped'][$groupName],
                            $group['posts']
                        )
                    );
                } else {
                    $favoritesByBusiness['grouped'][$groupName] = $group['posts'];
                }
            }
        }

        $groupedFavorites = array_merge(...$groupedFavorites);

        // If there were some favorites left without group put them into personal favorites group
        $favoritesByBusiness['grouped'][__('Asmeniškai sekami skelbimai', 'mnm')] = array_diff($favoritesByBusiness['all'], $groupedFavorites);

        return $favoritesByBusiness;
    }

    private function orderFavoritesByParameters(&$favorites, $parameter): void
    {
        $favorites['grouped'] = array_map(static function ($group) {
            $items = array_map(static function ($post) {
                return get_post($post);
            }, $group);

            if (!empty($parameter->order)) {
                [$column, $order] = explode(',', $parameter->order);
                if ($column === 'rand') {
                    shuffle($items);
                } else {
                    usort($items, static function ($a, $b) use ($column, $order) {
                        if ($order === 'asc') {
                            return $b->$column < $a->$column ? 1 : -1;
                        }

                        return $b->$column > $a->$column ? 1 : -1;
                    });
                }
            }

            return $items;
        }, $favorites['grouped']);

        if (!empty($parameter->order)) {
            [$column, $order] = explode(',', $parameter->order);
            $favorites['grouped'] = array_filter($favorites['grouped']);
            if ($column === 'rand') {
                shuffle($favorites['grouped']);
            } else {
                uasort($favorites['grouped'], static function ($a, $b) use ($column, $order) {
                    if ($order === 'asc') {
                        return $b[0]->$column < $a[0]->$column ? 1 : -1;
                    }

                    return $b[0]->$column > $a[0]->$column ? 1 : -1;
                });
            }
        }
    }
}
