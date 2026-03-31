<?php
if (!defined('_PS_VERSION_')) {
    exit;
}
require_once __DIR__ . '/classes/DecorairYtVideoItem.php';

class Decorairytvideo extends Module
{
    public function __construct()
    {
        $this->name = 'decorairytvideo';
        $this->tab = 'seo';
        $this->version = '2.4.11';
        $this->author = 'Decorair';
        $this->need_instance = 0;
        $this->bootstrap = true;
        parent::__construct();
        $this->displayName = $this->l('Decorair YT Video Schema');
        $this->description = $this->l('Manage multilingual YouTube VideoObject schema with single-language mode and autocomplete.');
        $this->ps_versions_compliancy = [
            'min' => '8.0.0',
            'max' => '9.99.99',
        ];
    }

    public function install()
    {
        return parent::install()
            && $this->registerHook('displayHeader')
            && $this->registerHook('displayBackOfficeHeader')
            && Configuration::updateValue('DECORAIRYTVIDEO_ENABLED', 1)
            && Configuration::updateValue('DECORAIRYTVIDEO_YT_API_KEY', '')
            && Configuration::updateValue('DECORAIRYTVIDEO_DELETE_DATA', 0)
            && $this->installDb();
    }

    public function uninstall()
    {
        if ((int) Configuration::get('DECORAIRYTVIDEO_DELETE_DATA')) {
            Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'decorairytvideo`');
            Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'decorairytvideo_lang`');
            Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'decorair_youtube_meta`');
        }

        return Configuration::deleteByName('DECORAIRYTVIDEO_ENABLED')
            && Configuration::deleteByName('DECORAIRYTVIDEO_YT_API_KEY')
            && Configuration::deleteByName('DECORAIRYTVIDEO_DELETE_DATA')
            && parent::uninstall();
    }

    public function getContent()
    {
        if ((int) Tools::getValue('ajax') === 1) {
            $action = (string) Tools::getValue('action');
            if ($action === 'searchDecorairYtVideoEntities') {
                $this->handleAjaxSearchEntities();
            }
            if ($action === 'fetchDecorairYtVideoMeta') {
                $this->handleAjaxFetchVideoMeta();
            }
            if ($action === 'testDecorairYtApiKey') {
                $this->handleAjaxTestApiKey();
            }
        }

        $this->ensureBackOfficeHeaderHookRegistered();
        $this->enqueueBackOfficeAssets();
        $this->ensureSchemaUpToDate();

        $output = '';
        if (Tools::getIsset('decorairytvideo_api_saved')) {
            $output .= $this->displayConfirmation($this->l('YouTube API key saved.'));
        }
        if (Tools::getIsset('decorairytvideo_saved')) {
            $output .= $this->displayConfirmation($this->l('Video saved successfully.'));
        }
        $requestedIdVideo = (int) Tools::getValue('id_decorairytvideo');
        $isInvalidRequestedVideo = false;

        if ($requestedIdVideo > 0) {
            $requestedVideo = new DecorairYtVideoItem($requestedIdVideo);
            if (!Validate::isLoadedObject($requestedVideo)) {
                $isInvalidRequestedVideo = true;
                $_GET['id_decorairytvideo'] = 0;
                $_POST['id_decorairytvideo'] = 0;
                unset($_GET['editVideo'], $_POST['editVideo']);
                $output .= $this->displayWarning($this->l('Selected video was not found. Switched to Add new video.'));
            }
        }

        if (Tools::isSubmit('submitDecorairYtVideoItem')) {
            $output .= $this->processVideoForm();
        }
        
        if (Tools::isSubmit('submitDecorairYtVideoApiKey')) {
            $apiKey = trim((string) Tools::getValue('decorairytvideo_yt_api_key'));

            Configuration::updateValue('DECORAIRYTVIDEO_YT_API_KEY', $apiKey);

            Configuration::updateValue(
                'DECORAIRYTVIDEO_DELETE_DATA',
                Tools::getIsset('decorairytvideo_delete_data') ? 1 : 0
            );

            Tools::redirectAdmin(
                AdminController::$currentIndex
                . '&configure=' . $this->name
                . '&token=' . Tools::getAdminTokenLite('AdminModules')
                . '&decorairytvideo_tab=settings'
                . '&decorairytvideo_api_saved=1'
            );
        }

        if (Tools::getIsset('deleteVideo')) {
            $idVideo = (int) Tools::getValue('id_decorairytvideo');
            if ($idVideo > 0) {
                $video = new DecorairYtVideoItem($idVideo);
                if (Validate::isLoadedObject($video) && $video->delete()) {
                    $output .= $this->displayConfirmation($this->l('Video deleted.'));
                } else {
                    $output .= $this->displayError($this->l('Could not delete video.'));
                }
            }
        }

        if (Tools::getIsset('toggleVideo')) {
            $idVideo = (int) Tools::getValue('id_decorairytvideo');
            if ($idVideo > 0) {
                $video = new DecorairYtVideoItem($idVideo);
                if (Validate::isLoadedObject($video)) {
                    $newActive = (int) !(int) $video->active;
                    $updated = Db::getInstance()->update(
                        'decorairytvideo',
                        ['active' => $newActive],
                        'id_decorairytvideo = ' . (int) $idVideo
                    );
                    if ($updated) {
                        $output .= $this->displayConfirmation($this->l('Video status updated.'));
                    } else {
                        $output .= $this->displayError($this->l('Could not update video status.'));
                    }
                }
            }
        }

        return $output
            . $this->renderBackOfficeTabs()
            . $this->renderAuthorCredit();
    }

    public function hookDisplayBackOfficeHeader()
    {
        if (!$this->isModuleConfigPage()) {
            return;
        }

        $this->enqueueBackOfficeAssets();
    }

    protected function isModuleConfigPage()
    {
        return Tools::getValue('configure') === $this->name;
    }

    protected function ensureBackOfficeHeaderHookRegistered()
    {
        if (!$this->id || $this->isRegisteredInHook('displayBackOfficeHeader')) {
            return;
        }

        $this->registerHook('displayBackOfficeHeader');
    }

    protected function enqueueBackOfficeAssets()
    {
        static $assetsLoaded = false;

        if ($assetsLoaded
            || !$this->isModuleConfigPage()
            || empty($this->context->controller)
            || !($this->context->controller instanceof AdminModulesController)
        ) {
            return;
        }

        $this->context->controller->addJS($this->_path . 'views/js/admin.js');
        $this->context->controller->addCSS($this->_path . 'views/css/admin.css');

        Media::addJsDef([
            'decorairYtVideoAjaxUrl' => $this->context->link->getAdminLink('AdminModules', true, [], [
                'configure' => $this->name,
                'decorairytvideo_ajax' => 1,
                'ajax' => 1,
            ]),
            'decorairYtVideoAutocompleteTexts' => [
                'noResults' => $this->l('No results found'),
                'typeMore' => $this->l('Type at least 2 characters'),
                'searching' => $this->l('Searching...'),
                'startTyping' => $this->l('Start typing to search'),
            ],
        ]);

        $assetsLoaded = true;
    }

    protected function sendJson(array $payload)
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/json; charset=utf-8');
        die(json_encode($payload));
    }

    protected function handleAjaxSearchEntities()
    {
        $type = (string) Tools::getValue('entity_type');
        $query = trim((string) Tools::getValue('q'));
        $results = [];
        $shopId = (int) $this->context->shop->id;
        $langId = (int) $this->context->language->id;
        $querySql = pSQL($query);
        $numericId = ctype_digit($query) ? (int) $query : 0;

        if ($query === '' || Tools::strlen($query) < 2) {
            $this->sendJson(['results' => []]);
        }

        if ($type === 'product') {
            $where = 'pl.name LIKE "%' . $querySql . '%"';
            if ($numericId > 0) {
                $where .= ' OR p.id_product = ' . $numericId;
            }

            $rows = Db::getInstance()->executeS(
                'SELECT p.id_product, pl.name
                 FROM `' . _DB_PREFIX_ . 'product` p
                 INNER JOIN `' . _DB_PREFIX_ . 'product_lang` pl
                   ON (p.id_product = pl.id_product
                       AND pl.id_lang = ' . $langId . '
                       AND pl.id_shop = ' . $shopId . ')
                 WHERE ' . $where . '
                 ORDER BY
                    CASE WHEN p.id_product = ' . $numericId . ' THEN 0 ELSE 1 END ASC,
                    pl.name ASC
                 LIMIT 20'
            );

            foreach ((array) $rows as $row) {
                $results[] = [
                    'id' => (int) $row['id_product'],
                    'label' => '#' . (int) $row['id_product'] . ' - ' . $row['name'],
                ];
            }
        } elseif ($type === 'category') {
            $where = 'cl.name LIKE "%' . $querySql . '%"';
            if ($numericId > 0) {
                $where .= ' OR c.id_category = ' . $numericId;
            }

            $rows = Db::getInstance()->executeS(
                'SELECT c.id_category, cl.name
                 FROM `' . _DB_PREFIX_ . 'category` c
                 INNER JOIN `' . _DB_PREFIX_ . 'category_lang` cl
                   ON (c.id_category = cl.id_category
                       AND cl.id_lang = ' . $langId . '
                       AND cl.id_shop = ' . $shopId . ')
                 WHERE ' . $where . '
                 ORDER BY
                    CASE WHEN c.id_category = ' . $numericId . ' THEN 0 ELSE 1 END ASC,
                    cl.name ASC
                 LIMIT 20'
            );

            foreach ((array) $rows as $row) {
                $results[] = [
                    'id' => (int) $row['id_category'],
                    'label' => '#' . (int) $row['id_category'] . ' - ' . $row['name'],
                ];
            }
        }

        $this->sendJson(['results' => $results]);
    }

    protected function handleAjaxFetchVideoMeta()
    {
        $youtubeInput = trim((string) Tools::getValue('youtube_input', Tools::getValue('youtube_id')));
        $apiKey = trim((string) Configuration::get('DECORAIRYTVIDEO_YT_API_KEY'));
        $idShop = (int) $this->context->shop->id;
        $idLang = (int) $this->context->language->id;

        if ($youtubeInput === '') {
            $this->sendJson([
                'success' => false,
                'message' => $this->l('YouTube URL or video ID is required.'),
                'title' => '',
                'description' => '',
                'duration_iso' => '',
                'duration_seconds' => null,
                'duration_formatted' => '',
                'thumbnail_url' => '',
                'upload_date' => '',
                'published_at' => '',
                'fetched_at' => '',
            ]);
        }

        if ($apiKey === '') {
            $this->sendJson([
                'success' => false,
                'message' => $this->l('YouTube API key is missing. Please save it in module settings first.'),
                'title' => '',
                'description' => '',
                'duration_iso' => '',
                'duration_seconds' => null,
                'duration_formatted' => '',
                'thumbnail_url' => '',
                'upload_date' => '',
                'published_at' => '',
                'fetched_at' => '',
            ]);
        }

        $youtubeId = $this->extractYoutubeVideoId($youtubeInput);
        if ($youtubeId === false) {
            $this->sendJson([
                'success' => false,
                'message' => $this->l('Invalid YouTube URL or video ID format.'),
                'title' => '',
                'description' => '',
                'duration_iso' => '',
                'duration_seconds' => null,
                'duration_formatted' => '',
                'thumbnail_url' => '',
                'upload_date' => '',
                'published_at' => '',
                'fetched_at' => '',
            ]);
        }

        $meta = $this->fetchYouTubeVideoMeta($youtubeId, $apiKey);
        if (!$meta['success']) {
            $this->sendJson([
                'success' => false,
                'message' => isset($meta['message']) ? (string) $meta['message'] : $this->l('Metadata fetch failed.'),
                'title' => isset($meta['title']) ? (string) $meta['title'] : '',
                'description' => isset($meta['description']) ? (string) $meta['description'] : '',
                'duration_iso' => isset($meta['duration']) ? (string) $meta['duration'] : '',
                'duration_seconds' => isset($meta['duration_seconds']) && $meta['duration_seconds'] !== null ? (int) $meta['duration_seconds'] : null,
                'duration_formatted' => isset($meta['duration_formatted']) ? (string) $meta['duration_formatted'] : '',
                'thumbnail_url' => isset($meta['thumbnail_url']) ? (string) $meta['thumbnail_url'] : '',
                'upload_date' => $this->normalizeDateYmd(isset($meta['published_at']) ? (string) $meta['published_at'] : ''),
                'published_at' => isset($meta['published_at']) ? (string) $meta['published_at'] : '',
                'fetched_at' => isset($meta['fetched_at']) ? (string) $meta['fetched_at'] : '',
            ]);
        }

        $saved = $this->saveYoutubeMetadata($idShop, $idLang, $youtubeInput, $meta);
        if (!$saved) {
            $this->sendJson([
                'success' => false,
                'message' => $this->l('Metadata fetched but could not be saved into database.'),
                'title' => (string) $meta['title'],
                'description' => (string) $meta['description'],
                'duration_iso' => (string) $meta['duration'],
                'duration_seconds' => $meta['duration_seconds'] !== null ? (int) $meta['duration_seconds'] : null,
                'duration_formatted' => (string) $meta['duration_formatted'],
                'thumbnail_url' => (string) $meta['thumbnail_url'],
                'upload_date' => $this->normalizeDateYmd(isset($meta['published_at']) ? (string) $meta['published_at'] : ''),
                'published_at' => (string) $meta['published_at'],
                'fetched_at' => (string) $meta['fetched_at'],
            ]);
        }

        $this->sendJson([
            'success' => true,
            'message' => $this->l('Metadata fetched successfully.'),
            'video_id' => (string) $meta['video_id'],
            'title' => (string) $meta['title'],
            'description' => (string) $meta['description'],
            'duration_iso' => (string) $meta['duration'],
            'duration' => (string) $meta['duration'],
            'duration_seconds' => $meta['duration_seconds'] !== null ? (int) $meta['duration_seconds'] : null,
            'duration_formatted' => (string) $meta['duration_formatted'],
            'thumbnail_url' => (string) $meta['thumbnail_url'],
            'channel_title' => (string) $meta['channel_title'],
            'upload_date' => $this->normalizeDateYmd(isset($meta['published_at']) ? (string) $meta['published_at'] : ''),
            'published_at' => (string) $meta['published_at'],
            'fetched_at' => (string) $meta['fetched_at'],
        ]);
    }

    protected function handleAjaxTestApiKey()
    {
        $apiKey = trim((string) Configuration::get('DECORAIRYTVIDEO_YT_API_KEY'));
        if ($apiKey === '') {
            $this->sendJson([
                'success' => false,
                'message' => $this->l('YouTube API key is missing.'),
            ]);
        }

        $result = $this->testYouTubeApiKey($apiKey);
        $this->sendJson($result);
    }

    protected function extractYoutubeVideoId($input)
    {
        $value = trim((string) $input);
        if ($value === '') {
            return false;
        }

        if (preg_match('/^[A-Za-z0-9_-]{11}$/', $value)) {
            return $value;
        }

        $parts = @parse_url($value);
        if (!is_array($parts) || empty($parts['host'])) {
            return false;
        }

        $host = strtolower((string) $parts['host']);
        $path = isset($parts['path']) ? trim((string) $parts['path'], '/') : '';

        if (in_array($host, ['youtu.be', 'www.youtu.be'], true) && $path !== '') {
            $candidate = strtok($path, '/');
            return preg_match('/^[A-Za-z0-9_-]{11}$/', $candidate) ? $candidate : false;
        }

        if (strpos($host, 'youtube.com') !== false) {
            if (!empty($parts['query'])) {
                parse_str((string) $parts['query'], $queryParams);
                if (!empty($queryParams['v']) && preg_match('/^[A-Za-z0-9_-]{11}$/', (string) $queryParams['v'])) {
                    return (string) $queryParams['v'];
                }
            }

            if ($path !== '') {
                $segments = explode('/', $path);
                if (count($segments) >= 2 && in_array($segments[0], ['embed', 'shorts'], true)) {
                    $candidate = $segments[1];
                    return preg_match('/^[A-Za-z0-9_-]{11}$/', $candidate) ? $candidate : false;
                }
            }
        }

        return false;
    }

    protected function parseIso8601DurationToSeconds($duration)
    {
        $duration = trim((string) $duration);
        if ($duration === '') {
            return null;
        }

        if (!preg_match('/^P(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)$/', $duration, $matches)) {
            return null;
        }

        $hours = isset($matches[1]) && $matches[1] !== '' ? (int) $matches[1] : 0;
        $minutes = isset($matches[2]) && $matches[2] !== '' ? (int) $matches[2] : 0;
        $seconds = isset($matches[3]) && $matches[3] !== '' ? (int) $matches[3] : 0;

        return ($hours * 3600) + ($minutes * 60) + $seconds;
    }

    protected function formatDurationFromSeconds($seconds)
    {
        $seconds = (int) $seconds;
        if ($seconds < 0) {
            $seconds = 0;
        }

        $hours = (int) floor($seconds / 3600);
        $minutes = (int) floor(($seconds % 3600) / 60);
        $secs = (int) ($seconds % 60);

        if ($hours > 0) {
            return $hours . ':' . str_pad((string) $minutes, 2, '0', STR_PAD_LEFT) . ':' . str_pad((string) $secs, 2, '0', STR_PAD_LEFT);
        }

        return $minutes . ':' . str_pad((string) $secs, 2, '0', STR_PAD_LEFT);
    }

    protected function normalizeDateYmd($value)
    {
        $value = trim((string) $value);
        if ($value === '' || $value === '0000-00-00' || $value === '0000-00-00 00:00:00') {
            return '';
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return '';
        }

        return date('Y-m-d', $timestamp);
    }

    protected function formatToIso8601($value)
    {
        $value = trim((string) $value);
        if ($value === '' || $value === '0000-00-00' || $value === '0000-00-00 00:00:00') {
            return '';
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return '';
        }

        return date('c', $timestamp);
    }

    protected function fetchYouTubeVideoMeta($youtubeId, $apiKey)
    {
        $endpoint = 'https://www.googleapis.com/youtube/v3/videos?part=contentDetails,snippet&id='
            . rawurlencode((string) $youtubeId)
            . '&key=' . rawurlencode((string) $apiKey);

        $response = '';
        if (function_exists('curl_init')) {
            $ch = curl_init($endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
            $response = curl_exec($ch);
            $curlError = curl_error($ch);
            curl_close($ch);
            if ($response === false) {
                return [
                    'success' => false,
                    'message' => $this->l('YouTube API request failed: ') . (string) $curlError,
                    'title' => '',
                    'description' => '',
                    'duration' => '',
                    'duration_seconds' => null,
                    'duration_formatted' => '',
                    'thumbnail_url' => '',
                    'channel_title' => '',
                    'published_at' => '',
                    'fetched_at' => '',
                ];
            }
        } else {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 15,
                    'header' => "Accept: application/json\r\n",
                ],
            ]);
            $response = @Tools::file_get_contents($endpoint, false, $context);
        }

        if (!is_string($response) || $response === '') {
            return [
                'success' => false,
                'message' => $this->l('Could not reach YouTube API.'),
                'title' => '',
                'description' => '',
                'duration' => '',
                'duration_seconds' => null,
                'duration_formatted' => '',
                'thumbnail_url' => '',
                'channel_title' => '',
                'published_at' => '',
                'fetched_at' => '',
            ];
        }

        $payload = json_decode($response, true);
        if (!is_array($payload)) {
            return [
                'success' => false,
                'message' => $this->l('Invalid response from YouTube API.'),
                'title' => '',
                'description' => '',
                'duration' => '',
                'duration_seconds' => null,
                'duration_formatted' => '',
                'thumbnail_url' => '',
                'channel_title' => '',
                'published_at' => '',
                'fetched_at' => '',
            ];
        }

        if (!empty($payload['error']['message'])) {
            return [
                'success' => false,
                'message' => $this->l('YouTube API error: ') . (string) $payload['error']['message'],
                'title' => '',
                'description' => '',
                'duration' => '',
                'duration_seconds' => null,
                'duration_formatted' => '',
                'thumbnail_url' => '',
                'channel_title' => '',
                'published_at' => '',
                'fetched_at' => '',
            ];
        }

        $item = !empty($payload['items'][0]) && is_array($payload['items'][0]) ? $payload['items'][0] : null;
        if (!$item) {
            return [
                'success' => false,
                'message' => $this->l('Video not found on YouTube.'),
                'title' => '',
                'description' => '',
                'duration' => '',
                'duration_seconds' => null,
                'duration_formatted' => '',
                'thumbnail_url' => '',
                'channel_title' => '',
                'published_at' => '',
                'fetched_at' => '',
            ];
        }

        $duration = '';
        if (!empty($item['contentDetails']['duration'])) {
            $duration = (string) $item['contentDetails']['duration'];
        }
        $durationSeconds = $this->parseIso8601DurationToSeconds($duration);
        $durationFormatted = $durationSeconds !== null ? $this->formatDurationFromSeconds($durationSeconds) : '';

        $thumbnailUrl = '';
        if (!empty($item['snippet']['thumbnails']) && is_array($item['snippet']['thumbnails'])) {
            $thumbnailUrl = $this->getBestYouTubeThumbnailUrl($item['snippet']['thumbnails']);
        }
        if ($thumbnailUrl === '') {
            $thumbnailUrl = 'https://i.ytimg.com/vi/' . (string) $youtubeId . '/hqdefault.jpg';
        }

        $title = !empty($item['snippet']['title']) ? (string) $item['snippet']['title'] : '';
        $description = !empty($item['snippet']['description']) ? (string) $item['snippet']['description'] : '';
        $channelTitle = !empty($item['snippet']['channelTitle']) ? (string) $item['snippet']['channelTitle'] : '';
        $publishedAt = '';
        if (!empty($item['snippet']['publishedAt'])) {
            $timestamp = strtotime((string) $item['snippet']['publishedAt']);
            if ($timestamp !== false) {
                $publishedAt = date('Y-m-d H:i:s', $timestamp);
            }
        }
        $fetchedAt = date('Y-m-d H:i:s');

        return [
            'success' => true,
            'video_id' => (string) $youtubeId,
            'youtube_url' => 'https://www.youtube.com/watch?v=' . (string) $youtubeId,
            'title' => $title,
            'description' => $description,
            'duration' => $duration,
            'duration_seconds' => $durationSeconds,
            'duration_formatted' => $durationFormatted,
            'thumbnail_url' => $thumbnailUrl,
            'channel_title' => $channelTitle,
            'published_at' => $publishedAt,
            'fetched_at' => $fetchedAt,
        ];
    }

    protected function testYouTubeApiKey($apiKey)
    {
        $endpoint = 'https://www.googleapis.com/youtube/v3/videos?part=snippet&id=dQw4w9WgXcQ&key='
            . rawurlencode((string) $apiKey);

        $response = '';
        if (function_exists('curl_init')) {
            $ch = curl_init($endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
            $response = curl_exec($ch);
            $curlError = curl_error($ch);
            curl_close($ch);
            if ($response === false) {
                return [
                    'success' => false,
                    'message' => $this->l('Could not reach YouTube API.')
                        . ($curlError !== '' ? (' ' . $curlError) : ''),
                ];
            }
        } else {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 15,
                    'header' => "Accept: application/json\r\n",
                ],
            ]);
            $response = @Tools::file_get_contents($endpoint, false, $context);
        }

        if (!is_string($response) || $response === '') {
            return [
                'success' => false,
                'message' => $this->l('Could not reach YouTube API.'),
            ];
        }

        $payload = json_decode($response, true);
        if (!is_array($payload)) {
            return [
                'success' => false,
                'message' => $this->l('Could not reach YouTube API.'),
            ];
        }

        if (!empty($payload['error']['message'])) {
            return [
                'success' => false,
                'message' => (string) $payload['error']['message'],
            ];
        }

        if (!empty($payload['items'][0])) {
            return [
                'success' => true,
                'message' => $this->l('API key is valid.'),
            ];
        }

        return [
            'success' => false,
            'message' => $this->l('Could not reach YouTube API.'),
        ];
    }

    protected function getBestYouTubeThumbnailUrl(array $thumbnails)
    {
        $qualities = ['maxres', 'standard', 'high', 'medium', 'default'];
        foreach ($qualities as $quality) {
            if (!empty($thumbnails[$quality]['url'])) {
                return (string) $thumbnails[$quality]['url'];
            }
        }
        return '';
    }

    protected function saveYoutubeMetadata($idShop, $idLang, $youtubeUrl, array $meta)
    {
        $videoId = isset($meta['video_id']) ? trim((string) $meta['video_id']) : '';
        if ($videoId === '') {
            return false;
        }

        $idShop = (int) $idShop;
        $idLang = (int) $idLang;
        $youtubeUrl = trim((string) $youtubeUrl);
        $title = isset($meta['title']) ? (string) $meta['title'] : '';
        $description = isset($meta['description']) ? (string) $meta['description'] : '';
        $durationIso = isset($meta['duration']) ? (string) $meta['duration'] : '';
        $durationSeconds = isset($meta['duration_seconds']) && $meta['duration_seconds'] !== null ? (int) $meta['duration_seconds'] : null;
        $thumbnailUrl = isset($meta['thumbnail_url']) ? (string) $meta['thumbnail_url'] : '';
        $channelTitle = isset($meta['channel_title']) ? (string) $meta['channel_title'] : '';
        $publishedAt = isset($meta['published_at']) ? (string) $meta['published_at'] : null;
        $fetchedAt = isset($meta['fetched_at']) ? (string) $meta['fetched_at'] : date('Y-m-d H:i:s');
        $now = date('Y-m-d H:i:s');

        $sql = 'INSERT INTO `' . _DB_PREFIX_ . 'decorair_youtube_meta`
            (`id_shop`, `id_lang`, `video_id`, `youtube_url`, `title`, `description`, `duration_iso`, `duration_seconds`, `thumbnail_url`, `channel_title`, `published_at`, `fetched_at`, `date_add`, `date_upd`)
            VALUES (
                ' . $idShop . ',
                ' . $idLang . ',
                "' . pSQL($videoId) . '",
                ' . ($youtubeUrl !== '' ? '"' . pSQL($youtubeUrl) . '"' : 'NULL') . ',
                ' . ($title !== '' ? '"' . pSQL($title) . '"' : 'NULL') . ',
                ' . ($description !== '' ? '"' . pSQL($description, true) . '"' : 'NULL') . ',
                ' . ($durationIso !== '' ? '"' . pSQL($durationIso) . '"' : 'NULL') . ',
                ' . ($durationSeconds !== null ? (int) $durationSeconds : 'NULL') . ',
                ' . ($thumbnailUrl !== '' ? '"' . pSQL($thumbnailUrl) . '"' : 'NULL') . ',
                ' . ($channelTitle !== '' ? '"' . pSQL($channelTitle) . '"' : 'NULL') . ',
                ' . ($publishedAt ? '"' . pSQL($publishedAt) . '"' : 'NULL') . ',
                "' . pSQL($fetchedAt) . '",
                "' . pSQL($now) . '",
                "' . pSQL($now) . '"
            )
            ON DUPLICATE KEY UPDATE
                `youtube_url` = VALUES(`youtube_url`),
                `title` = VALUES(`title`),
                `description` = VALUES(`description`),
                `duration_iso` = VALUES(`duration_iso`),
                `duration_seconds` = VALUES(`duration_seconds`),
                `thumbnail_url` = VALUES(`thumbnail_url`),
                `channel_title` = VALUES(`channel_title`),
                `published_at` = VALUES(`published_at`),
                `fetched_at` = VALUES(`fetched_at`),
                `date_upd` = VALUES(`date_upd`)';

        return (bool) Db::getInstance()->execute($sql);
    }

    protected function getYoutubeMetadata($idShop, $idLang, $videoId)
    {
        $videoId = trim((string) $videoId);
        if ($videoId === '') {
            return null;
        }

        $idShop = (int) $idShop;
        $idLang = (int) $idLang;

        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'decorair_youtube_meta`
             WHERE id_shop = ' . $idShop . '
               AND video_id = "' . pSQL($videoId) . '"
               AND id_lang IN (' . $idLang . ', 0)
             ORDER BY CASE WHEN id_lang = ' . $idLang . ' THEN 0 ELSE 1 END
             LIMIT 1'
        );

        return $row ?: null;
    }

    protected function getYoutubeMetadataMap($idShop, $idLang, array $videoIds)
    {
        $cleanIds = [];
        foreach ($videoIds as $videoId) {
            $videoId = trim((string) $videoId);
            if ($videoId !== '') {
                $cleanIds[$videoId] = $videoId;
            }
        }

        if (empty($cleanIds)) {
            return [];
        }

        $quoted = [];
        foreach ($cleanIds as $videoId) {
            $quoted[] = '"' . pSQL($videoId) . '"';
        }

        $rows = Db::getInstance()->executeS(
            'SELECT *
             FROM `' . _DB_PREFIX_ . 'decorair_youtube_meta`
             WHERE id_shop = ' . (int) $idShop . '
               AND id_lang IN (' . (int) $idLang . ', 0)
               AND video_id IN (' . implode(', ', $quoted) . ')
             ORDER BY CASE WHEN id_lang = ' . (int) $idLang . ' THEN 0 ELSE 1 END'
        );

        $map = [];
        foreach ((array) $rows as $row) {
            $videoId = (string) $row['video_id'];
            if ($videoId === '' || isset($map[$videoId])) {
                continue;
            }
            $map[$videoId] = $row;
        }

        return $map;
    }

    protected function renderYouTubeApiKeyForm()
    {
        $apiKey = trim((string) Configuration::get('DECORAIRYTVIDEO_YT_API_KEY'));
        $deleteData = (int) Configuration::get('DECORAIRYTVIDEO_DELETE_DATA');
        $html = '<div class="panel"><h3><i class="icon-key"></i> ' . $this->l('YouTube Data API') . '</h3>';
        $html .= '<form method="post" id="decorairytvideo-api-key-form">';
        $html .= '<div class="form-group">';
        $html .= '<label class="control-label">' . $this->l('API key') . '</label>';
        $html .= '<input type="text" class="form-control fixed-width-xxl" name="decorairytvideo_yt_api_key" value="' . htmlspecialchars($apiKey, ENT_QUOTES, 'UTF-8') . '">';
        $html .= '<p class="help-block">' . $this->l('Used for metadata fetch (duration and thumbnail).') . '</p>';
        $html .= '</div>';
        $html .= '<div class="form-group">';
        $html .= '<div class="checkbox">';
        $html .= '<label>';
        $html .= '<input type="checkbox" name="decorairytvideo_delete_data" value="1"' . ($deleteData ? ' checked="checked"' : '') . '> ';
        $html .= $this->l('Delete all data when uninstalling module');
        $html .= '</label>';
        $html .= '</div>';
        $html .= '<p class="help-block">' . $this->l('If enabled, all module tables will be permanently removed during uninstall.') . '</p>';
        $html .= '</div>';
        $html .= '<div class="decorairytvideo-api-key-actions">';
        $html .= '<button type="submit" class="btn btn-default" name="submitDecorairYtVideoApiKey"><i class="icon-save"></i> ' . $this->l('Save settings') . '</button> ';
        $html .= '<button type="button" id="decorairytvideo-test-api-key" class="btn btn-default">' . $this->l('Test API key') . '</button>';
        $html .= '<span id="decorairytvideo-api-key-test-notice" class="decorairytvideo-api-key-test-notice" aria-live="polite"></span>';
        $html .= '</div>';
        $html .= '</form></div>';
        return $html;
    }

    protected function installDb()
    {
        $sql = [];
        $sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'decorairytvideo` (
            `id_decorairytvideo` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `youtube_id` VARCHAR(64) DEFAULT NULL,
            `thumbnail_url` VARCHAR(512) DEFAULT NULL,
            `upload_date` DATE DEFAULT NULL,
            `duration` VARCHAR(32) DEFAULT NULL,
            `scope_type` VARCHAR(16) NOT NULL DEFAULT "global",
            `id_product` INT(11) UNSIGNED NOT NULL DEFAULT 0,
            `id_category` INT(11) UNSIGNED NOT NULL DEFAULT 0,
            `language_mode` VARCHAR(16) NOT NULL DEFAULT "multi",
            `single_lang_id` INT(11) UNSIGNED NOT NULL DEFAULT 0,
            `position` INT(11) UNSIGNED NOT NULL DEFAULT 0,
            `active` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
            PRIMARY KEY (`id_decorairytvideo`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';
        $sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'decorairytvideo_lang` (
            `id_decorairytvideo` INT(11) UNSIGNED NOT NULL,
            `id_lang` INT(11) UNSIGNED NOT NULL,
            `youtube_id` VARCHAR(64) DEFAULT NULL,
            `title` VARCHAR(255) NOT NULL,
            `description` TEXT NULL,
            PRIMARY KEY (`id_decorairytvideo`, `id_lang`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';
        $sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'decorair_youtube_meta` (
            `id_youtube_meta` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_shop` INT UNSIGNED NOT NULL DEFAULT 1,
            `id_lang` INT UNSIGNED NOT NULL DEFAULT 0,
            `video_id` VARCHAR(32) NOT NULL,
            `youtube_url` VARCHAR(255) DEFAULT NULL,
            `title` VARCHAR(255) DEFAULT NULL,
            `description` TEXT DEFAULT NULL,
            `duration_iso` VARCHAR(32) DEFAULT NULL,
            `duration_seconds` INT UNSIGNED DEFAULT NULL,
            `thumbnail_url` VARCHAR(500) DEFAULT NULL,
            `channel_title` VARCHAR(255) DEFAULT NULL,
            `published_at` DATETIME DEFAULT NULL,
            `fetched_at` DATETIME DEFAULT NULL,
            `date_add` DATETIME NOT NULL,
            `date_upd` DATETIME NOT NULL,
            PRIMARY KEY (`id_youtube_meta`),
            UNIQUE KEY `uniq_shop_lang_video` (`id_shop`, `id_lang`, `video_id`),
            KEY `idx_video_id` (`video_id`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';
        foreach ($sql as $query) {
            if (!Db::getInstance()->execute($query)) {
                return false;
            }
        }
        return $this->ensureSchemaUpToDate();
    }

    protected function uninstallDb()
    {
        return Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'decorairytvideo_lang`')
            && Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'decorair_youtube_meta`')
            && Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'decorairytvideo`');
    }

    protected function tableExists($table)
    {
        return !empty(Db::getInstance()->executeS("SHOW TABLES LIKE '" . _DB_PREFIX_ . pSQL($table) . "'"));
    }

    protected function columnExists($table, $column)
    {
        if (!$this->tableExists($table)) {
            return false;
        }
        return !empty(Db::getInstance()->executeS(
            'SHOW COLUMNS FROM `' . _DB_PREFIX_ . pSQL($table) . '` LIKE "' . pSQL($column) . '"'
        ));
    }

    protected function ensureColumn($table, $column, $sql)
    {
        if (!$this->columnExists($table, $column)) {
            Db::getInstance()->execute($sql);
        }
    }

    protected function ensureSchemaUpToDate()
    {
        if (!$this->tableExists('decorairytvideo')) {
            return false;
        }
        $this->ensureColumn('decorairytvideo', 'youtube_id', 'ALTER TABLE `' . _DB_PREFIX_ . 'decorairytvideo` ADD `youtube_id` VARCHAR(64) DEFAULT NULL AFTER `id_decorairytvideo`');
        $this->ensureColumn('decorairytvideo', 'thumbnail_url', 'ALTER TABLE `' . _DB_PREFIX_ . 'decorairytvideo` ADD `thumbnail_url` VARCHAR(512) DEFAULT NULL AFTER `youtube_id`');
        $this->ensureColumn('decorairytvideo', 'duration', 'ALTER TABLE `' . _DB_PREFIX_ . 'decorairytvideo` ADD `duration` VARCHAR(32) DEFAULT NULL AFTER `upload_date`');
        $this->ensureColumn('decorairytvideo', 'scope_type', 'ALTER TABLE `' . _DB_PREFIX_ . 'decorairytvideo` ADD `scope_type` VARCHAR(16) NOT NULL DEFAULT "global" AFTER `duration`');
        $this->ensureColumn('decorairytvideo', 'id_product', 'ALTER TABLE `' . _DB_PREFIX_ . 'decorairytvideo` ADD `id_product` INT(11) UNSIGNED NOT NULL DEFAULT 0 AFTER `scope_type`');
        $this->ensureColumn('decorairytvideo', 'id_category', 'ALTER TABLE `' . _DB_PREFIX_ . 'decorairytvideo` ADD `id_category` INT(11) UNSIGNED NOT NULL DEFAULT 0 AFTER `id_product`');
        $this->ensureColumn('decorairytvideo', 'language_mode', 'ALTER TABLE `' . _DB_PREFIX_ . 'decorairytvideo` ADD `language_mode` VARCHAR(16) NOT NULL DEFAULT "multi" AFTER `id_category`');
        $this->ensureColumn('decorairytvideo', 'single_lang_id', 'ALTER TABLE `' . _DB_PREFIX_ . 'decorairytvideo` ADD `single_lang_id` INT(11) UNSIGNED NOT NULL DEFAULT 0 AFTER `language_mode`');
        $this->ensureColumn('decorairytvideo_lang', 'youtube_id', 'ALTER TABLE `' . _DB_PREFIX_ . 'decorairytvideo_lang` ADD `youtube_id` VARCHAR(64) DEFAULT NULL AFTER `id_lang`');
        Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'decorair_youtube_meta` (
                `id_youtube_meta` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_shop` INT UNSIGNED NOT NULL DEFAULT 1,
                `id_lang` INT UNSIGNED NOT NULL DEFAULT 0,
                `video_id` VARCHAR(32) NOT NULL,
                `youtube_url` VARCHAR(255) DEFAULT NULL,
                `title` VARCHAR(255) DEFAULT NULL,
                `description` TEXT DEFAULT NULL,
                `duration_iso` VARCHAR(32) DEFAULT NULL,
                `duration_seconds` INT UNSIGNED DEFAULT NULL,
                `thumbnail_url` VARCHAR(500) DEFAULT NULL,
                `channel_title` VARCHAR(255) DEFAULT NULL,
                `published_at` DATETIME DEFAULT NULL,
                `fetched_at` DATETIME DEFAULT NULL,
                `date_add` DATETIME NOT NULL,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id_youtube_meta`),
                UNIQUE KEY `uniq_shop_lang_video` (`id_shop`, `id_lang`, `video_id`),
                KEY `idx_video_id` (`video_id`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;'
        );
        $this->migrateYoutubeIdToMainTable();
        return true;
    }

    protected function migrateYoutubeIdToMainTable()
    {
        if (!$this->columnExists('decorairytvideo', 'youtube_id') || !$this->tableExists('decorairytvideo_lang')) {
            return;
        }

        Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . 'decorairytvideo` v
             SET v.youtube_id = (
                SELECT vl.youtube_id
                FROM `' . _DB_PREFIX_ . 'decorairytvideo_lang` vl
                WHERE vl.id_decorairytvideo = v.id_decorairytvideo
                  AND vl.youtube_id IS NOT NULL
                  AND vl.youtube_id <> ""
                ORDER BY vl.id_lang ASC
                LIMIT 1
             )
             WHERE (v.youtube_id IS NULL OR v.youtube_id = "")
               AND EXISTS (
                  SELECT 1
                  FROM `' . _DB_PREFIX_ . 'decorairytvideo_lang` v2
                  WHERE v2.id_decorairytvideo = v.id_decorairytvideo
                    AND v2.youtube_id IS NOT NULL
                    AND v2.youtube_id <> ""
               )'
        );
    }

    protected function getSelectedListLangId()
    {
        $requested = (int) Tools::getValue('yt_list_lang');
        return $requested > 0 ? $requested : (int) $this->context->language->id;
    }

    protected function renderLanguageFilter()
    {
        $langs = Language::getLanguages(false);
        $selected = $this->getSelectedListLangId();

        $html = '<div class="panel"><form method="get">';
        $html .= '<input type="hidden" name="controller" value="AdminModules">';
        $html .= '<input type="hidden" name="configure" value="' . htmlspecialchars($this->name, ENT_QUOTES, 'UTF-8') . '">';
        $html .= '<input type="hidden" name="token" value="' . htmlspecialchars(Tools::getAdminTokenLite('AdminModules'), ENT_QUOTES, 'UTF-8') . '">';
        $html .= '<input type="hidden" name="decorairytvideo_tab" value="videos">';
        $html .= '<div class="form-group"><label><strong>' . $this->l('Language filter for Video list') . '</strong></label>';
        $html .= '<select name="yt_list_lang" class="form-control fixed-width-xl" onchange="this.form.submit()">';
        foreach ($langs as $lang) {
            $idLang = (int) $lang['id_lang'];
            $sel = $idLang === $selected ? ' selected="selected"' : '';
            $html .= '<option value="' . $idLang . '"' . $sel . '>' . strtoupper($lang['iso_code']) . ' - ' . htmlspecialchars($lang['name']) . '</option>';
        }
        $html .= '</select></div></form></div>';
        return $html;
    }

    protected function renderBackOfficeTabs()
    {
        $supportedIso = ['sk', 'en', 'de', 'fr', 'es'];
        $isoNormalizationMap = [
            'gb' => 'en',
        ];
        $iso = '';

        if (isset($this->context->employee) && Validate::isLoadedObject($this->context->employee)) {
            $employeeLangId = (int) $this->context->employee->id_lang;
            if ($employeeLangId > 0) {
                $iso = strtolower((string) Language::getIsoById($employeeLangId));
            }
        }

        if ($iso === '' && isset($this->context->language) && Validate::isLoadedObject($this->context->language)) {
            $contextLangId = (int) $this->context->language->id;
            if ($contextLangId > 0) {
                $iso = strtolower((string) Language::getIsoById($contextLangId));
            }
        }

        if (isset($isoNormalizationMap[$iso])) {
            $iso = $isoNormalizationMap[$iso];
        }

        if (!in_array($iso, $supportedIso, true)) {
            $iso = 'en';
        }

        $tabLabels = [
            'en' => [
                'settings' => 'YouTube Data API / settings',
                'videos' => 'Video management',
                'guide' => 'Guide',
                'support' => 'Support the project',
            ],
            'sk' => [
                'settings' => 'YouTube Data API / nastavenia',
                'videos' => 'Správa videí',
                'guide' => 'Návod',
                'support' => 'Podporte projekt',
            ],
            'de' => [
                'settings' => 'YouTube Data API / Einstellungen',
                'videos' => 'Videoverwaltung',
                'guide' => 'Anleitung',
                'support' => 'Projekt unterstützen',
            ],
            'fr' => [
                'settings' => 'YouTube Data API / paramètres',
                'videos' => 'Gestion des vidéos',
                'guide' => 'Guide',
                'support' => 'Soutenir le projet',
            ],
            'es' => [
                'settings' => 'YouTube Data API / ajustes',
                'videos' => 'Gestión de vídeos',
                'guide' => 'Guía',
                'support' => 'Apoyar el proyecto',
            ],
        ];
        $labels = $tabLabels[$iso];

        $allowedTabs = ['settings', 'videos', 'guide', 'support'];
        $activeTab = (string) Tools::getValue('decorairytvideo_tab', 'settings');
        if (!in_array($activeTab, $allowedTabs, true)) {
            $activeTab = 'settings';
        }

        $settingsActive = $activeTab === 'settings' ? ' active' : '';
        $videosActive = $activeTab === 'videos' ? ' active' : '';
        $guideActive = $activeTab === 'guide' ? ' active' : '';
        $supportActive = $activeTab === 'support' ? ' active' : '';

        $html = '<div class="panel">';
        $html .= '<ul class="nav nav-tabs" role="tablist">';
        $html .= '<li class="' . trim($settingsActive) . '"><a href="#decorairytvideo-tab-settings" role="tab" data-toggle="tab">' . htmlspecialchars($labels['settings'], ENT_QUOTES, 'UTF-8') . '</a></li>';
        $html .= '<li class="' . trim($videosActive) . '"><a href="#decorairytvideo-tab-videos" role="tab" data-toggle="tab">' . htmlspecialchars($labels['videos'], ENT_QUOTES, 'UTF-8') . '</a></li>';
        $html .= '<li class="' . trim($guideActive) . '"><a href="#decorairytvideo-tab-guide" role="tab" data-toggle="tab">' . htmlspecialchars($labels['guide'], ENT_QUOTES, 'UTF-8') . '</a></li>';
        $html .= '<li class="' . trim($supportActive) . '"><a href="#decorairytvideo-tab-support" role="tab" data-toggle="tab">' . htmlspecialchars($labels['support'], ENT_QUOTES, 'UTF-8') . '</a></li>';
        $html .= '</ul>';

        $html .= '<div class="tab-content" style="padding-top:15px;">';
        $html .= '<div class="tab-pane' . $settingsActive . '" id="decorairytvideo-tab-settings">' . $this->renderYouTubeApiKeyForm() . '</div>';
        $html .= '<div class="tab-pane' . $videosActive . '" id="decorairytvideo-tab-videos">' . $this->renderLanguageFilter() . $this->renderVideoList() . $this->renderVideoForm() . '</div>';
        $html .= '<div class="tab-pane' . $guideActive . '" id="decorairytvideo-tab-guide">' . $this->renderGuideTab() . '</div>';
        $html .= '<div class="tab-pane' . $supportActive . '" id="decorairytvideo-tab-support">' . $this->renderSupportTab() . '</div>';
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    protected function renderGuideTab()
    {
        $supportedIso = ['sk', 'en', 'de', 'fr', 'es'];
        $isoNormalizationMap = [
            'gb' => 'en',
        ];
        $iso = '';

        if (isset($this->context->employee) && Validate::isLoadedObject($this->context->employee)) {
            $employeeLangId = (int) $this->context->employee->id_lang;
            if ($employeeLangId > 0) {
                $iso = strtolower((string) Language::getIsoById($employeeLangId));
            }
        }

        if ($iso === '' && isset($this->context->language) && Validate::isLoadedObject($this->context->language)) {
            $contextLangId = (int) $this->context->language->id;
            if ($contextLangId > 0) {
                $iso = strtolower((string) Language::getIsoById($contextLangId));
            }
        }

        if (isset($isoNormalizationMap[$iso])) {
            $iso = $isoNormalizationMap[$iso];
        }

        if (!in_array($iso, $supportedIso, true)) {
            $iso = 'en';
        }

        $content = [];

        $content['en']  = '<p><strong>About module:</strong> Manage YouTube videos for products and categories, generate VideoObject JSON-LD, fetch metadata via YouTube API and store them in DB.</p>';
        $content['en'] .= '<p><strong>Main features:</strong> YouTube URL / ID input, Fetch from YouTube, title/duration/thumbnail/upload date preview, single language mode, multi language mode, global/category/product scope, language filter in list, API key test button, DB-based metadata cache without live FO requests.</p>';
        $content['en'] .= '<h4 style="margin-top:14px;">Google Cloud API onboarding</h4><ol><li>Go to <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener noreferrer">console.cloud.google.com/apis/credentials</a>.</li><li>Create credentials -> API key.</li><li>Enable YouTube Data API v3.</li><li>On BasicAuth test sites use Application restrictions = None.</li><li>Save API key in this module and test it.</li></ol>';
        $content['en'] .= '<h4>SEO tips</h4><ul><li>VideoObject helps rich results.</li><li>DB cache keeps quota usage low.</li><li>Keep upload date and duration filled.</li></ul>';
        $content['en'] .= '<h4>Troubleshooting</h4><ul><li>Missing API key</li><li>Invalid key</li><li>BasicAuth + restrictions blocking access</li><li>Invalid YouTube ID</li></ul>';

        $content['de']  = '<p><strong>Modulübersicht:</strong> Verwalten Sie YouTube-Videos für Produkte und Kategorien, erzeugen Sie VideoObject JSON-LD und speichern Sie YouTube-Metadaten in der Datenbank.</p>';
        $content['de'] .= '<p><strong>Hauptfunktionen:</strong> YouTube URL/ID Feld, Fetch from YouTube, Vorschau für Titel/Dauer/Thumbnail/Upload-Datum, Single-Language-Mode, Multi-Language-Mode, Scope global/kategorie/produkt, Sprachfilter in der Videoliste, API-Key-Test und DB-Cache ohne Live-Requests im Frontend.</p>';
        $content['de'] .= '<h4 style="margin-top:14px;">Google Cloud API Einrichtung</h4><ol><li>Öffnen Sie <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener noreferrer">console.cloud.google.com/apis/credentials</a>.</li><li>Create credentials -> API key.</li><li>YouTube Data API v3 aktivieren.</li><li>Bei BasicAuth-Testseiten Application restrictions = None verwenden.</li><li>API-Key im Modul speichern und testen.</li></ol>';
        $content['de'] .= '<h4>SEO Tipps</h4><ul><li>VideoObject unterstützt Rich Results.</li><li>DB-Cache spart API-Quota.</li><li>Upload-Datum und Dauer pflegen.</li></ul>';
        $content['de'] .= '<h4>Fehlerbehebung</h4><ul><li>API-Key fehlt</li><li>Ungültiger API-Key</li><li>BasicAuth + Restriktionen blockieren Zugriff</li><li>Ungültige YouTube-ID</li></ul>';

        $content['fr']  = '<p><strong>Présentation du module:</strong> Gérez des vidéos YouTube pour produits et catégories, générez VideoObject JSON-LD et stockez les métadonnées YouTube dans la base de données.</p>';
        $content['fr'] .= '<p><strong>Fonctions principales:</strong> champ URL/ID YouTube, Fetch from YouTube, aperçu title/duration/thumbnail/upload date, mode langue unique, mode multilingue, scope global/catégorie/produit, filtre de langue dans la liste, bouton test API key, cache DB sans requêtes live en frontend.</p>';
        $content['fr'] .= '<h4 style="margin-top:14px;">Onboarding Google Cloud API</h4><ol><li>Ouvrez <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener noreferrer">console.cloud.google.com/apis/credentials</a>.</li><li>Create credentials -> API key.</li><li>Activez YouTube Data API v3.</li><li>Sur un site de test avec BasicAuth, utilisez Application restrictions = None.</li><li>Collez la clé API dans le module et testez-la.</li></ol>';
        $content['fr'] .= '<h4>Conseils SEO</h4><ul><li>VideoObject aide les résultats enrichis.</li><li>Le cache DB réduit la consommation de quota.</li><li>Renseignez upload date et duration.</li></ul>';
        $content['fr'] .= '<h4>Dépannage</h4><ul><li>Clé API manquante</li><li>Clé invalide</li><li>BasicAuth + restrictions bloquent l\'accès</li><li>ID YouTube invalide</li></ul>';

        $content['es']  = '<p><strong>Descripción del módulo:</strong> Gestiona vídeos de YouTube para productos y categorías, genera VideoObject JSON-LD y guarda metadatos de YouTube en la base de datos.</p>';
        $content['es'] .= '<p><strong>Funciones principales:</strong> campo URL/ID de YouTube, Fetch from YouTube, vista previa de title/duration/thumbnail/upload date, modo idioma único, modo multilenguaje, scope global/categoría/producto, filtro de idioma en la lista, botón de test API key y caché DB sin peticiones live en frontend.</p>';
        $content['es'] .= '<h4 style="margin-top:14px;">Onboarding Google Cloud API</h4><ol><li>Ve a <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener noreferrer">console.cloud.google.com/apis/credentials</a>.</li><li>Create credentials -> API key.</li><li>Activa YouTube Data API v3.</li><li>En web de prueba con BasicAuth usa Application restrictions = None.</li><li>Guarda la API key en el módulo y pruébala.</li></ol>';
        $content['es'] .= '<h4>Consejos SEO</h4><ul><li>VideoObject ayuda con rich results.</li><li>La caché en DB ahorra cuota.</li><li>Mantén upload date y duration completos.</li></ul>';
        $content['es'] .= '<h4>Solución de problemas</h4><ul><li>Falta API key</li><li>API key inválida</li><li>BasicAuth + restricciones bloquean acceso</li><li>ID de YouTube inválido</li></ul>';

        $content['sk']  = '<p><strong>Predstavenie modulu:</strong> Modul umožňuje správu YouTube videí pre produkty a kategórie, generuje VideoObject JSON-LD pre SEO a metadata z YouTube API ukladá do databázy.</p>';
        $content['sk'] .= '<p><strong>Hlavné funkcie:</strong> pole YouTube URL / ID, tlačidlo Fetch from YouTube, preview title/duration/thumbnail/upload date, single language mode, multi language mode, global/category/product scope, language filter vo video liste, API key test button a DB cache bez live requestov na FO.</p>';
        $content['sk'] .= '<h4 style="margin-top:14px;">Onboarding Google Cloud API</h4><ol><li>Choďte na <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener noreferrer">console.cloud.google.com/apis/credentials</a>.</li><li>Kliknite Create credentials -> API key.</li><li>Zapnite YouTube Data API v3.</li><li>Pri test webe s BasicAuth nastavte Application restrictions = None.</li><li>API key vložte do modulu, uložte a otestujte.</li></ol>';
        $content['sk'] .= '<h4>SEO tipy</h4><ul><li>VideoObject pomáha rich výsledkom.</li><li>Metadata v DB šetria quota.</li><li>Odporúčame mať vyplnený upload date a duration.</li></ul>';
        $content['sk'] .= '<h4>Troubleshooting</h4><ul><li>Chýba API key</li><li>Neplatný API key</li><li>BasicAuth + restrictions blokujú prístup</li><li>Neplatné YouTube ID</li></ul>';
        $content['sk'] .= '<div class="alert alert-warning" style="margin-top:10px;margin-bottom:0;">' . $this->l('Pri testovacom webe s heslom (BasicAuth) môže byť potrebné ponechať Application restrictions = None.') . '</div>';

        $selected = isset($content[$iso]) ? $iso : 'en';

        $html = '<div class="panel">';
        $html .= '<h3><i class="icon-book"></i> ' . $this->l('Návod') . '</h3>';
        $html .= '<p class="text-muted" style="margin-bottom:12px;">' . $this->l('Praktický návod pre nastavenie modulu, API key a SEO výstup pre YouTube videá.') . '</p>';
        $html .= '<div style="border:1px solid #e3e3e3;border-radius:4px;padding:14px;background:#fff;">';
        $html .= $content[$selected];
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    protected function renderSupportTab()
    {
        $supportedIso = ['sk', 'en', 'de', 'fr', 'es'];
        $isoNormalizationMap = [
            'gb' => 'en',
        ];
        $iso = '';

        if (isset($this->context->employee) && Validate::isLoadedObject($this->context->employee)) {
            $employeeLangId = (int) $this->context->employee->id_lang;
            if ($employeeLangId > 0) {
                $iso = strtolower((string) Language::getIsoById($employeeLangId));
            }
        }

        if ($iso === '' && isset($this->context->language) && Validate::isLoadedObject($this->context->language)) {
            $contextLangId = (int) $this->context->language->id;
            if ($contextLangId > 0) {
                $iso = strtolower((string) Language::getIsoById($contextLangId));
            }
        }

        if (isset($isoNormalizationMap[$iso])) {
            $iso = $isoNormalizationMap[$iso];
        }

        if (!in_array($iso, $supportedIso, true)) {
            $iso = 'en';
        }

        $content = [];
        $content['en'] = [
            'badge_free' => 'Free module',
            'badge_support' => 'Support development',
            'title' => 'Do you like this module?',
            'text_1' => 'This module is free. If it helped your shop, you can support its further development by purchasing in our store.',
            'text_2' => 'Every order helps us continue improving free tools for the PrestaShop community.',
            'button' => 'Support the project on decorair',
        ];
        $content['sk'] = [
            'badge_free' => 'Modul zdarma',
            'badge_support' => 'Podporte vývoj',
            'title' => 'Páči sa vám tento modul?',
            'text_1' => 'Modul je poskytovaný zdarma. Ak pomohol vášmu obchodu, môžete podporiť jeho ďalší vývoj nákupom v našom obchode.',
            'text_2' => 'Každá objednávka nám pomáha ďalej vylepšovať bezplatné nástroje pre komunitu PrestaShop.',
            'button' => 'Podporiť projekt na decorair',
        ];
        $content['de'] = [
            'badge_free' => 'Kostenloses Modul',
            'badge_support' => 'Entwicklung unterstützen',
            'title' => 'Gefällt Ihnen dieses Modul?',
            'text_1' => 'Dieses Modul ist kostenlos. Wenn es Ihrem Shop geholfen hat, können Sie seine Weiterentwicklung durch einen Kauf in unserem Shop unterstützen.',
            'text_2' => 'Jede Bestellung hilft uns, kostenlose Tools für die PrestaShop-Community weiter zu verbessern.',
            'button' => 'Projekt auf decorair unterstützen',
        ];
        $content['fr'] = [
            'badge_free' => 'Module gratuit',
            'badge_support' => 'Soutenir le développement',
            'title' => 'Vous aimez ce module ?',
            'text_1' => 'Ce module est gratuit. S\'il a aidé votre boutique, vous pouvez soutenir son développement en effectuant un achat dans notre boutique.',
            'text_2' => 'Chaque commande nous aide à améliorer les outils gratuits pour la communauté PrestaShop.',
            'button' => 'Soutenir le projet sur decorair',
        ];
        $content['es'] = [
            'badge_free' => 'Módulo gratuito',
            'badge_support' => 'Apoyar el desarrollo',
            'title' => '¿Te gusta este módulo?',
            'text_1' => 'Este módulo es gratuito. Si ayudó a tu tienda, puedes apoyar su desarrollo comprando en nuestra tienda.',
            'text_2' => 'Cada pedido nos ayuda a seguir mejorando herramientas gratuitas para la comunidad PrestaShop.',
            'button' => 'Apoyar el proyecto en decorair',
        ];
        $selected = isset($content[$iso]) ? $iso : 'en';

        $html = '<div class="panel" style="text-align:center;padding:24px 18px;">';
        $html .= '<div style="margin-bottom:12px;">';
        $html .= '<span style="display:inline-block;background:#eef5ff;color:#2b5dab;border:1px solid #d5e5ff;border-radius:999px;padding:4px 10px;font-size:12px;margin:0 4px 6px 4px;">' . htmlspecialchars($content[$selected]['badge_free'], ENT_QUOTES, 'UTF-8') . '</span>';
        $html .= '<span style="display:inline-block;background:#f3f7f3;color:#2f7a3a;border:1px solid #dcefdc;border-radius:999px;padding:4px 10px;font-size:12px;margin:0 4px 6px 4px;">' . htmlspecialchars($content[$selected]['badge_support'], ENT_QUOTES, 'UTF-8') . '</span>';
        $html .= '</div>';
        $html .= '<div style="margin:8px 0 14px 0;">';
        $html .= '<img class="support-logo" src="https://www.eshop.decorair.com/img/decorair-schema-logo.jpg" alt="Decorair" style="max-width:320px;width:100%;height:auto;border-radius:8px;">';
        $html .= '</div>';
        $html .= '<h3 style="margin:8px 0 10px 0;">' . htmlspecialchars($content[$selected]['title'], ENT_QUOTES, 'UTF-8') . '</h3>';
        $html .= '<p style="max-width:760px;margin:0 auto 8px auto;color:#555;">' . htmlspecialchars($content[$selected]['text_1'], ENT_QUOTES, 'UTF-8') . '</p>';
        $html .= '<p style="max-width:760px;margin:0 auto 16px auto;color:#555;">' . htmlspecialchars($content[$selected]['text_2'], ENT_QUOTES, 'UTF-8') . '</p>';
        $html .= '<p style="margin:0;"><a class="btn btn-primary btn-lg" href="https://www.eshop.decorair.com" target="_blank" rel="noopener noreferrer">' . htmlspecialchars($content[$selected]['button'], ENT_QUOTES, 'UTF-8') . '</a></p>';
        $html .= '</div>';

        return $html;
    }

    protected function renderAuthorCredit()
    {
        $html = '<div style="text-align:center;font-size:12px;color:#777;opacity:.85;margin:8px 0 2px 0;">';
        $html .= $this->l('Modul vytvoril') . ' <a href="https://www.eshop.decorair.com" target="_blank" rel="noopener noreferrer" style="color:#666;text-decoration:none;">decorair</a>';
        $html .= '</div>';

        return $html;
    }

    protected function getLanguageBadgeHtml($idVideo)
    {
        $langs = Language::getLanguages(false);
        $video = new DecorairYtVideoItem((int) $idVideo);
        $mode = Validate::isLoadedObject($video) ? (string) $video->language_mode : 'multi';
        $singleLangId = Validate::isLoadedObject($video) ? (int) $video->single_lang_id : 0;

        $rows = Db::getInstance()->executeS(
            'SELECT id_lang, title
             FROM `' . _DB_PREFIX_ . 'decorairytvideo_lang`
             WHERE id_decorairytvideo = ' . (int) $idVideo
        );

        $map = [];
        foreach ((array) $rows as $row) {
            $map[(int) $row['id_lang']] = trim((string) $row['title']) !== '';
        }

        $html = '';
        foreach ($langs as $lang) {
            $idLang = (int) $lang['id_lang'];
            if ($mode === 'single' && $singleLangId !== $idLang) {
                $bg = '#f5f5f5';
                $color = '#777';
            } else {
                $ok = !empty($map[$idLang]);
                $bg = $ok ? '#dff0d8' : '#f2dede';
                $color = $ok ? '#3c763d' : '#a94442';
            }
            $html .= '<span style="display:inline-block;margin:0 6px 6px 0;padding:3px 7px;border-radius:10px;background:' . $bg . ';color:' . $color . ';font-size:11px;">' . strtoupper($lang['iso_code']) . '</span>';
        }
        return $html;
    }

    protected function renderVideoList()
    {
        $listLangId = $this->getSelectedListLangId();
        $rows = Db::getInstance()->executeS(
            'SELECT v.id_decorairytvideo, v.youtube_id, v.thumbnail_url, v.upload_date, v.duration, v.scope_type, v.id_product, v.id_category, v.language_mode, v.single_lang_id, v.position, v.active, vl.title, vl.description
             FROM `' . _DB_PREFIX_ . 'decorairytvideo` v
             LEFT JOIN `' . _DB_PREFIX_ . 'decorairytvideo_lang` vl
               ON (v.id_decorairytvideo = vl.id_decorairytvideo AND vl.id_lang = ' . (int) $listLangId . ')
             ORDER BY v.position ASC, v.id_decorairytvideo ASC'
        );

        $html = '<div class="panel"><h3><i class="icon-list"></i> ' . $this->l('Video list') . '</h3><div class="table-responsive"><table class="table"><thead><tr>';
        $html .= '<th>ID</th><th>' . $this->l('Preview') . '</th><th>' . $this->l('Title') . '</th><th>' . $this->l('Description') . '</th><th>' . $this->l('YouTube ID') . '</th><th>' . $this->l('Languages') . '</th><th>' . $this->l('Language mode') . '</th><th>' . $this->l('Scope') . '</th><th>' . $this->l('Product') . '</th><th>' . $this->l('Category') . '</th><th>' . $this->l('Upload date') . '</th><th>' . $this->l('Duration') . '</th><th>' . $this->l('Position') . '</th><th>' . $this->l('Active') . '</th><th>' . $this->l('Actions') . '</th>';
        $html .= '</tr></thead><tbody>';

        foreach ((array) $rows as $video) {
            $token = Tools::getAdminTokenLite('AdminModules');
            $base = AdminController::$currentIndex . '&configure=' . $this->name . '&token=' . $token . '&decorairytvideo_tab=videos&yt_list_lang=' . (int) $listLangId . '&id_decorairytvideo=' . (int) $video['id_decorairytvideo'];
            $youtubeIdEsc = htmlspecialchars((string) $video['youtube_id'], ENT_QUOTES, 'UTF-8');

            $preview = '';
            if ($youtubeIdEsc !== '') {
                $watch = 'https://www.youtube.com/watch?v=' . $youtubeIdEsc;
                $storedThumb = trim((string) $video['thumbnail_url']);
                $thumbMax = $storedThumb !== '' ? $storedThumb : 'https://i.ytimg.com/vi/' . $youtubeIdEsc . '/maxresdefault.jpg';
                $thumbHq = 'https://i.ytimg.com/vi/' . $youtubeIdEsc . '/hqdefault.jpg';
                $preview = '<a href="' . $watch . '" target="_blank" rel="noopener noreferrer" style="display:inline-block;position:relative;width:120px;height:68px;border:1px solid #ddd;background:#fff;overflow:hidden;text-decoration:none;">';
                $preview .= '<img src="' . htmlspecialchars($thumbMax, ENT_QUOTES, 'UTF-8') . '" data-fallback-src="' . htmlspecialchars($thumbHq, ENT_QUOTES, 'UTF-8') . '" style="width:120px;height:68px;object-fit:cover;display:block;" loading="lazy" onerror="if(!this.dataset.fallbackApplied){this.dataset.fallbackApplied=&quot;1&quot;;this.src=this.dataset.fallbackSrc;}">';
                $preview .= '<span style="position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);width:34px;height:24px;background:rgba(255,0,0,.85);border-radius:8px;display:flex;align-items:center;justify-content:center;">';
                $preview .= '<span style="display:block;width:0;height:0;border-top:6px solid transparent;border-bottom:6px solid transparent;border-left:10px solid #fff;margin-left:2px;"></span>';
                $preview .= '</span></a>';
            }

            $deleteConfirm = htmlspecialchars($this->l('Delete this video?'), ENT_QUOTES, 'UTF-8');
            $titleClean = trim((string) strip_tags((string) $video['title']));
            $titleClean = preg_replace('/\s+/', ' ', $titleClean);
            $titlePreview = '';
            $titleTitleAttr = '';
            if ($titleClean !== '') {
                $titleTruncated = Tools::strlen($titleClean) > 30;
                $titlePreview = $titleTruncated ? Tools::substr($titleClean, 0, 30) . '...' : $titleClean;
                $titlePreview = htmlspecialchars($titlePreview, ENT_QUOTES, 'UTF-8');
                $titleTitleAttr = ' title="' . htmlspecialchars($titleClean, ENT_QUOTES, 'UTF-8') . '"';
            }
            $descriptionClean = trim((string) strip_tags((string) $video['description']));
            $descriptionClean = preg_replace('/\s+/', ' ', $descriptionClean);
            $descriptionPreview = '';
            $descriptionTitleAttr = '';
            if ($descriptionClean !== '') {
                $descriptionTruncated = Tools::strlen($descriptionClean) > 30;
                $descriptionPreview = $descriptionTruncated ? Tools::substr($descriptionClean, 0, 30) . '...' : $descriptionClean;
                $descriptionPreview = htmlspecialchars($descriptionPreview, ENT_QUOTES, 'UTF-8');
                $descriptionTitleAttr = ' title="' . htmlspecialchars($descriptionClean, ENT_QUOTES, 'UTF-8') . '"';
            }

            $html .= '<tr>';
            $html .= '<td>' . (int) $video['id_decorairytvideo'] . '</td>';
            $html .= '<td>' . $preview . '</td>';
            $html .= '<td class="decorairytvideo-title-cell"' . $titleTitleAttr . '>' . $titlePreview . '</td>';
            $html .= '<td class="decorairytvideo-desc-cell"' . $descriptionTitleAttr . '>' . $descriptionPreview . '</td>';
            $html .= '<td>' . $youtubeIdEsc . '</td>';
            $html .= '<td>' . $this->getLanguageBadgeHtml((int) $video['id_decorairytvideo']) . '</td>';
            $html .= '<td>' . htmlspecialchars((string) $video['language_mode']) . '</td>';
            $html .= '<td>' . htmlspecialchars((string) $video['scope_type']) . '</td>';
            $html .= '<td>' . (int) $video['id_product'] . '</td>';
            $html .= '<td>' . (int) $video['id_category'] . '</td>';
            $html .= '<td>' . htmlspecialchars((string) $video['upload_date']) . '</td>';
            $html .= '<td>' . htmlspecialchars((string) $video['duration']) . '</td>';
            $html .= '<td>' . (int) $video['position'] . '</td>';
            $html .= '<td>' . ((int) $video['active'] ? $this->l('Yes') : $this->l('No')) . '</td>';
            $html .= '<td>';
            $html .= '<a class="btn btn-default" href="' . $base . '&editVideo=1"><i class="icon-pencil"></i> ' . $this->l('Edit') . '</a> ';
            $html .= '<a class="btn btn-default" href="' . $base . '&toggleVideo=1"><i class="icon-power-off"></i> ' . $this->l('Toggle') . '</a> ';
            $html .= "<a class=\"btn btn-danger\" href=\"" . $base . "&deleteVideo=1\" onclick=\"return confirm('" . $deleteConfirm . "');\"><i class=\"icon-trash\"></i> " . $this->l('Delete') . "</a>";
            $html .= '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table></div></div>';
        return $html;
    }

    protected function getLanguageModeOptions()
    {
        return [
            ['value' => 'multi', 'label' => $this->l('Multi language')],
            ['value' => 'single', 'label' => $this->l('Single language')],
        ];
    }

    protected function getSingleLanguageOptions()
    {
        $opts = [];
        foreach (Language::getLanguages(false) as $lang) {
            $opts[] = ['value' => (int) $lang['id_lang'], 'label' => strtoupper($lang['iso_code']) . ' - ' . $lang['name']];
        }
        return $opts;
    }

    protected function getProductLabelById($idProduct)
    {
        if ($idProduct <= 0) return '';
        $row = Db::getInstance()->getRow('SELECT pl.name FROM `' . _DB_PREFIX_ . 'product_lang` pl WHERE pl.id_product = ' . (int) $idProduct . ' AND pl.id_lang = ' . (int) $this->context->language->id . ' AND pl.id_shop = ' . (int) $this->context->shop->id);
        return $row ? ('#' . (int) $idProduct . ' - ' . $row['name']) : '';
    }

    protected function getCategoryLabelById($idCategory)
    {
        if ($idCategory <= 0) return '';
        $row = Db::getInstance()->getRow('SELECT cl.name FROM `' . _DB_PREFIX_ . 'category_lang` cl WHERE cl.id_category = ' . (int) $idCategory . ' AND cl.id_lang = ' . (int) $this->context->language->id . ' AND cl.id_shop = ' . (int) $this->context->shop->id);
        return $row ? ('#' . (int) $idCategory . ' - ' . $row['name']) : '';
    }

    protected function renderVideoForm()
    {
        $idVideo = (int) Tools::getValue('id_decorairytvideo');
        $video = $idVideo ? new DecorairYtVideoItem($idVideo) : new DecorairYtVideoItem();
        $scopeOptions = [
            ['value' => 'global', 'label' => $this->l('Global')],
            ['value' => 'category', 'label' => $this->l('Category')],
            ['value' => 'product', 'label' => $this->l('Product')],
        ];

        $fields = ['form' => ['legend' => ['title' => $idVideo ? $this->l('Edit video') : $this->l('Add new video'), 'icon' => 'icon-youtube'], 'input' => [
            ['type' => 'hidden', 'name' => 'id_decorairytvideo'],
            ['type' => 'hidden', 'name' => 'thumbnail_url'],
            ['type' => 'text', 'label' => $this->l('YouTube URL / ID'), 'name' => 'youtube_id', 'required' => true, 'hint' => $this->l('Paste full YouTube URL or 11-character ID. Shared for all languages.')],
            ['type' => 'text', 'label' => $this->l('Upload date'), 'name' => 'upload_date', 'required' => false, 'hint' => $this->l('Format: YYYY-MM-DD')],
            ['type' => 'text', 'label' => $this->l('Duration'), 'name' => 'duration', 'required' => false, 'hint' => $this->l('ISO 8601 format, example: PT1M30S')],
            ['type' => 'select', 'label' => $this->l('Language mode'), 'name' => 'language_mode', 'options' => ['query' => $this->getLanguageModeOptions(), 'id' => 'value', 'name' => 'label']],
            ['type' => 'select', 'label' => $this->l('Single language'), 'name' => 'single_lang_id', 'desc' => $this->l('Used only when Language mode is set to Single language.'), 'options' => ['query' => $this->getSingleLanguageOptions(), 'id' => 'value', 'name' => 'label']],
            ['type' => 'select', 'label' => $this->l('Scope'), 'name' => 'scope_type', 'options' => ['query' => $scopeOptions, 'id' => 'value', 'name' => 'label']],
            ['type' => 'text', 'label' => $this->l('Product search'), 'name' => 'product_search_label', 'required' => false, 'hint' => $this->l('Start typing product name and select result.')],
            ['type' => 'hidden', 'name' => 'id_product'],
            ['type' => 'text', 'label' => $this->l('Category search'), 'name' => 'category_search_label', 'required' => false, 'hint' => $this->l('Start typing category name and select result.')],
            ['type' => 'hidden', 'name' => 'id_category'],
            ['type' => 'text', 'label' => $this->l('Position'), 'name' => 'position', 'required' => true],
            ['type' => 'switch', 'label' => $this->l('Active'), 'name' => 'active', 'is_bool' => true, 'values' => [['id' => 'video_active_on', 'value' => 1, 'label' => $this->l('Yes')], ['id' => 'video_active_off', 'value' => 0, 'label' => $this->l('No')]]],
            ['type' => 'text', 'label' => $this->l('Title'), 'name' => 'title', 'lang' => true, 'required' => false],
            ['type' => 'textarea', 'label' => $this->l('Description'), 'name' => 'description', 'lang' => true, 'autoload_rte' => false, 'rows' => 5, 'cols' => 60],
        ], 'submit' => ['title' => $this->l('Save video'), 'name' => 'submitDecorairYtVideoItem']]];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex
            . '&configure=' . $this->name
            . '&decorairytvideo_tab=videos'
            . '&yt_list_lang=' . (int) $this->getSelectedListLangId();
        $helper->submit_action = 'submitDecorairYtVideoItem';
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->allow_employee_form_lang = (int) Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);
        $helper->languages = $this->context->controller->getLanguages();
        $helper->id_language = (int) $this->context->language->id;

        $helper->fields_value['id_decorairytvideo'] = $idVideo;
        $youtubeIdDefault = '';
        if ($idVideo > 0) {
            $youtubeIdDefault = Db::getInstance()->getValue(
                'SELECT youtube_id FROM `' . _DB_PREFIX_ . 'decorairytvideo` WHERE id_decorairytvideo = ' . (int) $idVideo
            );
        }
        $youtubeIdValue = Tools::getValue('youtube_id', $youtubeIdDefault);
        if (is_array($youtubeIdValue)) {
            $youtubeIdValue = reset($youtubeIdValue);
        }
        $helper->fields_value['youtube_id'] = (string) $youtubeIdValue;
        $thumbnailValue = Tools::getValue('thumbnail_url', $idVideo ? (string) $video->thumbnail_url : '');
        if (is_array($thumbnailValue)) {
            $thumbnailValue = reset($thumbnailValue);
        }
        $helper->fields_value['thumbnail_url'] = (string) $thumbnailValue;
        $uploadDateDefault = $idVideo ? $this->normalizeDateYmd((string) $video->upload_date) : '';
        $helper->fields_value['upload_date'] = $this->normalizeDateYmd((string) Tools::getValue('upload_date', $uploadDateDefault));
        $helper->fields_value['duration'] = Tools::getValue('duration', $idVideo ? $video->duration : '');
        $helper->fields_value['language_mode'] = Tools::getValue('language_mode', $idVideo ? $video->language_mode : 'multi');
        $helper->fields_value['single_lang_id'] = Tools::getValue('single_lang_id', $idVideo ? (int) $video->single_lang_id : 0);
        $helper->fields_value['scope_type'] = Tools::getValue('scope_type', $idVideo ? $video->scope_type : 'global');
        $helper->fields_value['id_product'] = Tools::getValue('id_product', $idVideo ? (int) $video->id_product : 0);
        $helper->fields_value['id_category'] = Tools::getValue('id_category', $idVideo ? (int) $video->id_category : 0);
        $helper->fields_value['position'] = Tools::getValue('position', $idVideo ? (int) $video->position : 1);
        $helper->fields_value['active'] = Tools::getValue('active', $idVideo ? (int) $video->active : 1);
        $helper->fields_value['product_search_label'] = Tools::getValue('product_search_label', $this->getProductLabelById((int) ($idVideo ? $video->id_product : 0)));
        $helper->fields_value['category_search_label'] = Tools::getValue('category_search_label', $this->getCategoryLabelById((int) ($idVideo ? $video->id_category : 0)));

        foreach (Language::getLanguages(false) as $lang) {
            $idLang = (int) $lang['id_lang'];
            $helper->fields_value['title'][$idLang] = Tools::getValue('title_' . $idLang, $idVideo ? ($video->title[$idLang] ?? '') : '');
            $helper->fields_value['description'][$idLang] = Tools::getValue('description_' . $idLang, $idVideo ? ($video->description[$idLang] ?? '') : '');
        }

        return $helper->generateForm([$fields])
            . $this->renderYouTubeMetaHelper((string) $helper->fields_value['thumbnail_url'])
            . $this->renderCopyTextHelper();
    }

    protected function renderYouTubeMetaHelper($thumbnailUrl = '')
    {
        $apiKeyConfigured = trim((string) Configuration::get('DECORAIRYTVIDEO_YT_API_KEY')) !== '';
        $thumbClean = trim((string) $thumbnailUrl);
        $hasThumb = $thumbClean !== '';
        $thumbEsc = htmlspecialchars($thumbClean, ENT_QUOTES, 'UTF-8');

        $html = '<div id="decorairytvideo-meta-helper" class="panel decorairytvideo-meta-helper">';
        $html .= '<h3><i class="icon-youtube-play"></i> ' . $this->l('YouTube metadata') . '</h3>';
        if (!$apiKeyConfigured) {
            $html .= '<div class="alert alert-warning">' . $this->l('YouTube API key is not configured. Save it above before fetching metadata.') . '</div>';
        }
        $html .= '<button type="button" id="decorairytvideo-fetch-meta" class="btn btn-default">' . $this->l('Fetch from YouTube') . '</button>';
        $html .= '<span id="decorairytvideo-meta-notice" class="decorairytvideo-meta-notice" aria-live="polite"></span>';
        $html .= '<div id="decorairytvideo-meta-fields" class="decorairytvideo-meta-fields">';
        $html .= '<div><strong>' . $this->l('Title') . ':</strong> <span id="decorairytvideo-meta-title"></span></div>';
        $html .= '<div><strong>' . $this->l('Duration') . ':</strong> <span id="decorairytvideo-meta-duration"></span></div>';
        $html .= '<div><strong>' . $this->l('Upload date') . ':</strong> <span id="decorairytvideo-meta-upload-date"></span></div>';
        $html .= '<div><strong>' . $this->l('Fetched at') . ':</strong> <span id="decorairytvideo-meta-fetched-at"></span></div>';
        $html .= '</div>';
        $html .= '<div class="decorairytvideo-meta-preview"' . ($hasThumb ? '' : ' style="display:none"') . '>';
        $html .= '<img id="decorairytvideo-meta-thumb" src="' . $thumbEsc . '" alt="' . htmlspecialchars($this->l('YouTube thumbnail preview'), ENT_QUOTES, 'UTF-8') . '"' . ($hasThumb ? '' : ' data-empty="1"') . '>';
        $html .= '</div>';
        $html .= '<p class="help-block">' . $this->l('Fetches duration and best available thumbnail URL. Click Save video to persist.') . '</p>';
        $html .= '</div>';

        return $html;
    }

    protected function renderCopyTextHelper()
    {
        $langs = Language::getLanguages(false);
        $currentLangId = (int) $this->context->language->id;

        $html = '<div id="decorairytvideo-copy-helper" class="panel decorairytvideo-copy-helper">';
        $html .= '<h3><i class="icon-copy"></i> ' . $this->l('Copy text to languages') . '</h3>';
        $html .= '<div class="decorairytvideo-copy-row">';
        $html .= '<label class="control-label decorairytvideo-copy-label" for="decorairytvideo-copy-source">' . $this->l('Source language') . '</label>';
        $html .= '<select id="decorairytvideo-copy-source" class="form-control">';
        foreach ($langs as $lang) {
            $idLang = (int) $lang['id_lang'];
            $selected = $idLang === $currentLangId ? ' selected="selected"' : '';
            $html .= '<option value="' . $idLang . '"' . $selected . '>' . strtoupper($lang['iso_code']) . ' - ' . htmlspecialchars($lang['name'], ENT_QUOTES, 'UTF-8') . '</option>';
        }
        $html .= '</select></div>';

        $html .= '<div class="decorairytvideo-copy-row">';
        $html .= '<label class="control-label decorairytvideo-copy-label">' . $this->l('Target languages') . '</label>';
        $html .= '<div class="decorairytvideo-copy-targets">';
        foreach ($langs as $lang) {
            $idLang = (int) $lang['id_lang'];
            $label = strtoupper((string) $lang['iso_code']) . ' - ' . (string) $lang['name'];
            $html .= '<label class="decorairytvideo-copy-target-item">';
            $html .= '<input type="checkbox" class="decorairytvideo-copy-target" value="' . $idLang . '"> ';
            $html .= htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
            $html .= '</label>';
        }
        $html .= '</div></div>';

        $html .= '<div class="decorairytvideo-copy-actions">';
        $html .= '<button type="button" class="btn btn-default" data-copy-mode="title">' . $this->l('Copy title') . '</button> ';
        $html .= '<button type="button" class="btn btn-default" data-copy-mode="description">' . $this->l('Copy description') . '</button> ';
        $html .= '<button type="button" class="btn btn-primary" data-copy-mode="both">' . $this->l('Copy title + description') . '</button> ';
        $html .= '<button type="button" class="btn btn-default" data-copy-mode="title" data-copy-empty-only="1">' . $this->l('Copy title to empty only') . '</button> ';
        $html .= '<button type="button" class="btn btn-default" data-copy-mode="description" data-copy-empty-only="1">' . $this->l('Copy description to empty only') . '</button> ';
        $html .= '<button type="button" class="btn btn-default" data-copy-mode="both" data-copy-empty-only="1">' . $this->l('Copy title + description to empty only') . '</button>';
        $html .= '</div>';

        $html .= '<p class="help-block">' . $this->l('This copies text only in the form. Click Save video to persist changes.') . '</p>';
        $html .= '</div>';

        return $html;
    }

    protected function processVideoForm()
    {
        $this->ensureSchemaUpToDate();
        $idVideo = (int) Tools::getValue('id_decorairytvideo');
        $video = $idVideo ? new DecorairYtVideoItem($idVideo) : new DecorairYtVideoItem();
        if ($idVideo && !Validate::isLoadedObject($video)) {
            $idVideo = 0;
            $video = new DecorairYtVideoItem();
        }

        $uploadDate = trim((string) Tools::getValue('upload_date'));
        $youtubeIdRaw = Tools::getValue('youtube_id');
        if (is_array($youtubeIdRaw)) {
            $youtubeIdRaw = reset($youtubeIdRaw);
        }
        $youtubeInput = trim((string) $youtubeIdRaw);
        $youtubeId = $this->extractYoutubeVideoId($youtubeInput);
        $duration = trim((string) Tools::getValue('duration'));
        $thumbnailUrlRaw = Tools::getValue('thumbnail_url');
        if (is_array($thumbnailUrlRaw)) {
            $thumbnailUrlRaw = reset($thumbnailUrlRaw);
        }
        $thumbnailUrl = trim((string) $thumbnailUrlRaw);
        $languageMode = trim((string) Tools::getValue('language_mode'));
        $singleLangId = (int) Tools::getValue('single_lang_id');
        $scopeType = trim((string) Tools::getValue('scope_type'));
        $idProduct = (int) Tools::getValue('id_product');
        $idCategory = (int) Tools::getValue('id_category');
        $position = (int) Tools::getValue('position');
        $active = (int) Tools::getValue('active');

        if ($uploadDate === '0000-00-00') {
            $uploadDate = '';
        }
        if ($uploadDate !== '' && !Validate::isDateFormat($uploadDate)) return $this->displayError($this->l('Invalid upload date format.'));
        if ($youtubeInput === '') return $this->displayError($this->l('YouTube URL or video ID is required.'));
        if ($youtubeId === false) return $this->displayError($this->l('Invalid YouTube URL or video ID format.'));
        if ($duration !== '' && !preg_match('/^P(T(\d+H)?(\d+M)?(\d+S)?)?$/', $duration)) return $this->displayError($this->l('Invalid duration format. Use ISO 8601, for example PT1M30S.'));
        if (!in_array($scopeType, ['global', 'category', 'product'])) return $this->displayError($this->l('Invalid scope.'));
        if ($scopeType === 'product' && $idProduct <= 0) return $this->displayError($this->l('For product scope, select a product from Product search.'));
        if ($scopeType === 'category' && $idCategory <= 0) return $this->displayError($this->l('For category scope, select a category from Category search.'));
        if ($scopeType !== 'product') $idProduct = 0;
        if ($scopeType !== 'category') $idCategory = 0;
        if (!in_array($languageMode, ['multi', 'single'])) return $this->displayError($this->l('Invalid language mode.'));
        if ($languageMode === 'single' && $singleLangId <= 0) return $this->displayError($this->l('Select one language for single language mode.'));
        if ($languageMode !== 'single') $singleLangId = 0;

        $titles = [];
        $hasAtLeastOneTitle = false;
        foreach (Language::getLanguages(false) as $lang) {
            $idLang = (int) $lang['id_lang'];
            $titles[$idLang] = trim((string) Tools::getValue('title_' . $idLang));
            if ($titles[$idLang] !== '') {
                $hasAtLeastOneTitle = true;
            }
            if (Tools::strlen($titles[$idLang]) > 255) {
                return $this->displayError(
                    sprintf(
                        $this->l('Title is too long for language %s. Maximum length is 255 characters.'),
                        strtoupper((string) $lang['iso_code'])
                    )
                );
            }
        }

        if ($languageMode === 'multi' && !$hasAtLeastOneTitle) {
            return $this->displayError($this->l('At least one title is required.'));
        }

        if ($languageMode === 'single' && empty($titles[$singleLangId])) {
            return $this->displayError($this->l('Title is required for the selected single language.'));
        }

        $video->upload_date = $uploadDate ?: null;
        $video->youtube_id = (string) $youtubeId;
        $video->duration = $duration ?: null;
        $video->thumbnail_url = $thumbnailUrl !== '' ? $thumbnailUrl : null;
        $video->language_mode = $languageMode;
        $video->single_lang_id = $singleLangId;
        $video->scope_type = $scopeType;
        $video->id_product = $idProduct;
        $video->id_category = $idCategory;
        $video->position = $position;
        $video->active = $active;

        foreach (Language::getLanguages(false) as $lang) {
            $idLang = (int) $lang['id_lang'];
            $video->title[$idLang] = $titles[$idLang];
            $video->description[$idLang] = (string) Tools::getValue('description_' . $idLang);
        }
        $success = $idVideo ? $video->update() : $video->add();
        if ($success) {
            Tools::redirectAdmin(
                AdminController::$currentIndex
                . '&configure=' . $this->name
                . '&token=' . Tools::getAdminTokenLite('AdminModules')
                . '&decorairytvideo_tab=videos'
                . '&yt_list_lang=' . (int) $this->getSelectedListLangId()
                . '&decorairytvideo_saved=1'
            );
        }
        return $this->displayError($this->l('Could not save video.'));
    }

    public function hookDisplayHeader()
    {
        $this->ensureSchemaUpToDate();
        if (!(bool) Configuration::get('DECORAIRYTVIDEO_ENABLED')) return '';
        if (!$this->isEligiblePageForVideoSchema()) return '';
        $videos = $this->getActiveVideosForCurrentLanguageAndScope();
        return empty($videos) ? '' : $this->renderVideoSchemaJsonLd($videos);
    }

    protected function isEligiblePageForVideoSchema()
    {
        return $this->context && $this->context->controller && $this->context->controller->php_self === 'product';
    }

    protected function getCurrentProduct()
    {
        $idProduct = (int) Tools::getValue('id_product');
        if ($idProduct <= 0) return null;
        $product = new Product($idProduct, false, (int) $this->context->language->id);
        return Validate::isLoadedObject($product) ? $product : null;
    }

    protected function getCurrentProductCategoryIds($product)
    {
        if (!$product || !Validate::isLoadedObject($product)) return [];
        $categories = $product->getCategories();
        return is_array($categories) ? array_map('intval', $categories) : [];
    }

    protected function getActiveVideosForCurrentLanguageAndScope()
    {
        $product = $this->getCurrentProduct();
        if (!$product) return [];
        $idLang = (int) $this->context->language->id;
        $iso = strtolower((string) $this->context->language->iso_code);
        $idProduct = (int) $product->id;
        $categoryIds = $this->getCurrentProductCategoryIds($product);

        $rows = Db::getInstance()->executeS(
            'SELECT v.id_decorairytvideo, v.youtube_id, v.thumbnail_url, v.upload_date, v.duration, v.scope_type, v.id_product, v.id_category, v.language_mode, v.single_lang_id, v.position, v.active, vl.title, vl.description
             FROM `' . _DB_PREFIX_ . 'decorairytvideo` v
             LEFT JOIN `' . _DB_PREFIX_ . 'decorairytvideo_lang` vl
               ON (v.id_decorairytvideo = vl.id_decorairytvideo AND vl.id_lang = ' . $idLang . ')
             WHERE v.active = 1
             ORDER BY CASE
                 WHEN v.scope_type = "product" AND v.id_product = ' . $idProduct . ' THEN 1
                 WHEN v.scope_type = "category" THEN 2
                 WHEN v.scope_type = "global" THEN 3
                 ELSE 4 END ASC, v.position ASC, v.id_decorairytvideo ASC'
        );
        $metaMap = $this->getYoutubeMetadataMap((int) $this->context->shop->id, $idLang, array_column((array) $rows, 'youtube_id'));

        $videos = [];
        foreach ((array) $rows as $row) {
            $include = ($row['scope_type'] === 'global')
                || ($row['scope_type'] === 'product' && (int) $row['id_product'] === $idProduct)
                || ($row['scope_type'] === 'category' && in_array((int) $row['id_category'], $categoryIds));
            if (!$include) continue;
            if ($row['language_mode'] === 'single' && (int) $row['single_lang_id'] !== $idLang) continue;
            if (empty($row['youtube_id']) || empty($row['title'])) continue;
            $videoId = (string) $row['youtube_id'];
            $meta = isset($metaMap[$videoId]) ? $metaMap[$videoId] : null;

            $metaTitle = $meta && !empty($meta['title']) ? (string) $meta['title'] : '';
            $metaDescription = $meta && isset($meta['description']) ? (string) $meta['description'] : '';
            $metaDurationIso = $meta && !empty($meta['duration_iso']) ? (string) $meta['duration_iso'] : '';
            $metaThumbnail = $meta && !empty($meta['thumbnail_url']) ? (string) $meta['thumbnail_url'] : '';
            $metaPublishedAt = $meta && !empty($meta['published_at']) ? (string) $meta['published_at'] : '';
            $uploadDate = $metaPublishedAt !== '' ? $metaPublishedAt : $this->normalizeDateYmd((string) $row['upload_date']);
            if ($uploadDate === '') {
                $uploadDate = date('Y-m-d');
            }

            $videos[] = [
                'name' => $metaTitle !== '' ? $metaTitle : (string) $row['title'],
                'description' => $metaDescription !== '' ? $metaDescription : (string) $row['description'],
                'youtube_id' => $videoId,
                'thumbnail_url' => $metaThumbnail !== '' ? $metaThumbnail : (string) $row['thumbnail_url'],
                'inLanguage' => $iso === 'gb' ? 'en' : $iso,
                'uploadDate' => $uploadDate,
                'duration' => $metaDurationIso !== '' ? $metaDurationIso : (!empty($row['duration']) ? (string) $row['duration'] : null),
            ];
        }
        return $videos;
    }

    protected function renderVideoSchemaJsonLd(array $videos)
    {
        $graph = [];
        foreach ($videos as $video) {
            $youtubeId = trim((string) $video['youtube_id']);
            if ($youtubeId === '') continue;
            $storedThumb = trim((string) (isset($video['thumbnail_url']) ? $video['thumbnail_url'] : ''));
            $item = [
                '@type' => 'VideoObject',
                'embedUrl' => 'https://www.youtube.com/embed/' . $youtubeId,
                'contentUrl' => 'https://www.youtube.com/watch?v=' . $youtubeId,
                'publisher' => [
                    '@type' => 'Organization',
                    'name' => 'decorair',
                    'logo' => ['@type' => 'ImageObject', 'url' => 'https://www.eshop.decorair.com/img/logo.jpg'],
                ],
            ];
            if (!empty($video['name'])) {
                $item['name'] = (string) $video['name'];
            }
            if (!empty($video['description'])) {
                $item['description'] = (string) $video['description'];
            }
            if ($storedThumb !== '') {
                $item['thumbnailUrl'] = $storedThumb;
            }
            $uploadDateIso = $this->formatToIso8601(isset($video['uploadDate']) ? (string) $video['uploadDate'] : '');
            if ($uploadDateIso !== '') {
                $item['uploadDate'] = $uploadDateIso;
            }
            if (!empty($video['duration'])) {
                $item['duration'] = (string) $video['duration'];
            }
            if (!empty($video['inLanguage'])) {
                $item['inLanguage'] = (string) $video['inLanguage'];
            }
            $graph[] = $item;
        }
        if (empty($graph)) return '';
        return '<script type="application/ld+json">' . json_encode(['@context' => 'https://schema.org', '@graph' => $graph], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>';
    }
}
