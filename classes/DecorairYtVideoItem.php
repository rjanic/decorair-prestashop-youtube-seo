<?php
class DecorairYtVideoItem extends ObjectModel
{
    public $upload_date;
    public $duration;
    public $scope_type;
    public $id_product;
    public $id_category;
    public $language_mode;
    public $single_lang_id;
    public $position;
    public $active;

    public $youtube_id;
    public $thumbnail_url;
    public $title;
    public $description;

    public static $definition = [
        'table' => 'decorairytvideo',
        'primary' => 'id_decorairytvideo',
        'multilang' => true,
        'fields' => [
            'upload_date' => ['type' => self::TYPE_DATE, 'validate' => 'isDateFormat'],
            'duration' => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 32],
            'scope_type' => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 16],
            'id_product' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'],
            'id_category' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'],
            'language_mode' => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 16],
            'single_lang_id' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'],
            'position' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'],
            'active' => ['type' => self::TYPE_BOOL, 'validate' => 'isBool'],
            'youtube_id' => ['type' => self::TYPE_STRING, 'size' => 64],
            'thumbnail_url' => ['type' => self::TYPE_STRING, 'size' => 512],
            'title' => ['type' => self::TYPE_STRING, 'lang' => true, 'validate' => 'isCleanHtml', 'size' => 255],
            'description' => ['type' => self::TYPE_HTML, 'lang' => true, 'validate' => 'isCleanHtml'],
        ],
    ];
}
