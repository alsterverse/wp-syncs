# Syncs [![Build Status](https://travis-ci.org/isotopsweden/wp-syncs.svg?branch=master)](https://travis-ci.org/isotopsweden/wp-syncs)

> WIP - Requires PHP 7.0 and WordPress 4.6

Syncs will sync posts and terms between multisites

## Installation

```
composer require isotopsweden/wp-syncs
```

## Usage

Example configuration for post types:

```php
// With `register_post_type`
register_post_type( 'book', [
  'syncs' => true
] );

// With the filter.
add_filter( 'syncs_post_types', function ( $post_types ) {
  return ['page', 'post', 'book'];
} );
```

Example configuration for taxonomies:

```php
// With `register_taxonomy`
register_taxonomy( 'group', 'post', [
  'syncs' => true
] );

// With the filter.
add_filter( 'syncs_taxonomies', function ( $taxonomies ) {
  return ['tag', 'category', 'group'];
} );
```

## How the sync works

### Created data

When a new post, term or attachment is created on a multisite it will be created on the other multisites and all obeject ids will be linked with a global sync id that is stored in the `syncs` table like this:

| id | sync_id | object_id | object_type | site_id |
| -- | ------- | --------- | ----------- | ------- |
| 1  | 1       | 15        | post        | 1       |
| 2  | 1       | 32        | post        | 2       |
| 3  | 1       | 90        | post        | 3       |

All sync ids are stored as metadata on objects just because of `WP_Query`, when you read `sync_id` with `get_metadata` it actual reads from the `syncs` table.

### Updated data

When a post, term or attachment is updated on a multisite the master it's the multisite where the user is updating. The other objects on other multisites will be removed and created again with the same global sync id but with new object ids. That's why you can't use object id as a id, instead you have to use `sync_id` value. It can be access via `get_metadata` or `syncs()->get_sync_id( $object_id, $object_type, [$site_id = 0] )`

### Deleted data

When a post, term or attachment is deleted it will be deleted on all multisites and the `sync_id` will be deleted.

## License

MIT © Isotop
