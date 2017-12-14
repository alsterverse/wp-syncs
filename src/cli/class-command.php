<?php

namespace Isotop\Syncs\CLI;

use WP_CLI\Formatter;

class Command extends \WP_CLI_Command {

	/**
	 * Get formatter object based on supplied arguments.
	 *
	 * @param  array $assoc_args
	 *
	 * @return \WP_CLI\Formatter
	 */
	protected function get_formatter( $assoc_args ) {
		$args = $this->get_format_args( $assoc_args );

		return new Formatter( $args );
	}

	/**
	 * Get default fields for formatter.
	 *
	 * Class that extends this class should override this method.
	 *
	 * @return null|string|array
	 */
	protected function get_default_format_fields() {
		return null;
	}

	/**
	 * Get format args that will be passed into CLI Formatter.
	 *
	 * @param  array $assoc_args
	 *
	 * @return array
	 */
	protected function get_format_args( $assoc_args ) {
		$format_args = [
			'fields' => $this->get_default_format_fields(),
			'field'  => null,
			'format' => 'table',
		];

		if ( isset( $assoc_args['fields'] ) ) {
			$format_args['fields'] = $assoc_args['fields'];
		}

		if ( isset( $assoc_args['field'] ) ) {
			$format_args['field'] = $assoc_args['field'];
		}

		if ( ! empty( $assoc_args['format'] ) && in_array( $assoc_args['format'], ['count', 'ids', 'table', 'csv', 'json'], true ) ) {
			$format_args['format'] = $assoc_args['format'];
		}

		return $format_args;
	}
}
