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

## License

MIT © Isotop
