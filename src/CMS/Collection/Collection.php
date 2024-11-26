<?php

declare(strict_types=1);

namespace ON\Cms\Collection;

/*
{
	"collection": "articles",
	"meta": {
		"collection": "articles",
		"icon": "article",
		"note": "Blog posts",
		"display_template": "{{ title }}",
		"hidden": false,
		"singleton": false,
		"translations": [
			{
				"language": "en-US",
				"translation": "Articles"
			},
			{
				"language": "nl-NL",
				"translation": "Artikelen"
			}
		],
		"archive_field": "status",
		"archive_value": "archived",
		"unarchive_value": "draft",
		"archive_app_filter": true,
		"sort_field": "sort",
		"item_duplication_fields": null,
		"sort": 1
	},
	"schema": {
		"name": "pages",
		"comment": null
	},
	"fields": [
		{
			"field": "title",
			"type": "string",
			"meta": {
				"icon": "title"
			},
			"schema": {
				"is_primary_key": true,
				"is_nullable": false
			}
		}
	]
}
 */

/* Collections are data tables. Typically, you access items within a collection. */
class Collection
{
	public array $fields;

	public function __construct(
		public ?string $name = null,
		public ?string $icon = null,
		public ?string $note = null,
		public bool $hidden = false,

		/* A collection that only contains one single item */
		public bool $singleton = false,
		public ?string $color = null,
		public ?string $display_template = null,
		public ?string $preview_url = null,
		public bool $versioning = false,
		public ?string $archive_field = null,
		public ?string $archive_value = null,
		public ?string $unarchive_value = null,
		public bool $archive_app_filter = false,

		/** @var string[] */
		public array $item_duplication_fields = null,
		public ?string $accountability = null, // 'all' | 'activity' | null;
		public ?bool $system = null,

		// sort properties
		public ?string $sort_field = null,
		public ?int $sort = null,

		// if this colletions belongs to a major collection
		public ?string $group = null,
		public ?string $collapse = 'open', // 'open' | 'closed' | 'locked'
	) {

	}
}
