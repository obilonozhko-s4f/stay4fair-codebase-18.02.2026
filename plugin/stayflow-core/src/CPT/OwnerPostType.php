<?php

declare(strict_types=1);

namespace StayFlow\CPT;

final class OwnerPostType
{
    public const POST_TYPE = 'stayflow_owner';

    public const META_OWNER_COUNTRY = 'owner_country';
    public const META_OWNER_TYPE = 'owner_type';
    public const META_VAT_ID = 'vat_id';
    public const META_VAT_STATUS = 'vat_status';
    public const META_COMPLIANCE_FIELDS = 'compliance_fields';
    public const META_CONTACT_DATA = 'contact_data';

    public function register(): void
    {
        register_post_type(self::POST_TYPE, [
            'labels' => [
                'name' => __('Owners', 'stayflow-core'),
                'singular_name' => __('Owner', 'stayflow-core'),
            ],
            'public' => false,
            'publicly_queryable' => false,
            'exclude_from_search' => true,
            'show_ui' => true,
            'show_in_menu' => false,
            'show_in_rest' => false,
            'supports' => ['title', 'custom-fields'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ]);
    }
}