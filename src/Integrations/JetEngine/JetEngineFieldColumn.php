<?php
declare( strict_types=1 );

namespace ColumnKit\Integrations\JetEngine;

use ColumnKit\Columns\BaseColumn;
use ColumnKit\Columns\FilterableColumn;
use ColumnKit\Columns\SortableColumn;
use WP_Query;

/**
 * JetEngine meta-field column. JetEngine stores meta values as standard post meta keyed by the
 * field name, so render reads via get_post_meta. Field discovery walks
 * jet_engine()->meta_boxes->get_registered_boxes().
 *
 * Read-only display + sort + filter. Editing deferred to JetEngine's own admin UI.
 */
final class JetEngineFieldColumn extends BaseColumn implements SortableColumn, FilterableColumn {
	public function get_type(): string {
		return 'jetengine_field';
	}

	public function get_label(): string {
		return __( 'JetEngine Field', 'columnkit' );
	}

	public function get_description(): string {
		return __( 'Display a value from a JetEngine meta field. Auto-discovers fields from registered meta boxes.', 'columnkit' );
	}

	public function applies_to_screen( string $screen_key ): bool {
		return str_starts_with( $screen_key, 'post_type:' ) || $screen_key === 'media';
	}

	public function settings_fields(): array {
		return [
			[
				'key'      => 'field_name',
				'label'    => __( 'JetEngine Field', 'columnkit' ),
				'type'     => 'select',
				'options'  => $this->discover_field_options(),
				'required' => true,
			],
		];
	}

	public function sanitize_settings( array $input ): array {
		$out = parent::sanitize_settings( $input );
		if ( isset( $out['field_name'] ) ) {
			$out['field_name'] = preg_replace( '/[^A-Za-z0-9_\-.]/', '', $out['field_name'] );
		}
		return $out;
	}

	/** @return array<string, string> field_name => "Title (type)" */
	private function discover_field_options(): array {
		if ( ! function_exists( 'jet_engine' ) ) {
			return [];
		}
		$engine = jet_engine();
		if ( ! isset( $engine->meta_boxes ) || ! method_exists( $engine->meta_boxes, 'get_registered_boxes' ) ) {
			return [];
		}
		$out = [];
		try {
			$boxes = $engine->meta_boxes->get_registered_boxes();
		} catch ( \Throwable $e ) {
			return [];
		}
		if ( ! is_array( $boxes ) ) {
			return [];
		}
		foreach ( $boxes as $box ) {
			$fields = $box['args']['meta_fields'] ?? ( $box['meta_fields'] ?? [] );
			if ( ! is_array( $fields ) ) {
				continue;
			}
			foreach ( $fields as $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}
				$name  = (string) ( $field['name'] ?? '' );
				$title = (string) ( $field['title'] ?? $name );
				$type  = (string) ( $field['type'] ?? 'text' );
				if ( $name === '' ) {
					continue;
				}
				$out[ $name ] = sprintf( '%s (%s)', $title !== '' ? $title : $name, $type );
			}
		}
		return $out;
	}

	public function render( int $object_id, array $settings ): string {
		$field_name = (string) ( $settings['field_name'] ?? '' );
		if ( $field_name === '' ) {
			return '';
		}
		$value = get_post_meta( $object_id, $field_name, true );
		if ( $value === '' || $value === false || $value === null ) {
			return '';
		}
		if ( is_array( $value ) || is_object( $value ) ) {
			return esc_html( (string) wp_json_encode( $value ) );
		}
		// JetEngine sometimes stores serialized "media" fields as the attachment ID — render as
		// thumbnail if the value looks like a positive integer attachment ID that exists.
		if ( ctype_digit( (string) $value ) ) {
			$id   = (int) $value;
			$post = $id > 0 ? get_post( $id ) : null;
			if ( $post && $post->post_type === 'attachment' && wp_attachment_is_image( $id ) ) {
				$html = wp_get_attachment_image( $id, [ 40, 40 ], false, [ 'loading' => 'lazy', 'style' => 'max-height:40px;width:auto;' ] );
				if ( is_string( $html ) && $html !== '' ) {
					return $html;
				}
			}
		}
		return esc_html( (string) $value );
	}

	public function get_export_value( int $object_id, array $settings ): string {
		$field_name = (string) ( $settings['field_name'] ?? '' );
		if ( $field_name === '' ) {
			return '';
		}
		$raw = get_post_meta( $object_id, $field_name, true );
		if ( $raw === '' || $raw === false || $raw === null ) {
			return '';
		}
		if ( is_array( $raw ) || is_object( $raw ) ) {
			return (string) wp_json_encode( $raw );
		}
		return (string) $raw;
	}

	// ------------------------------------------------------------------
	// SortableColumn — meta_value LEFT JOIN
	// ------------------------------------------------------------------

	public function apply_sort( WP_Query $query, array $settings, string $order ): void {
		$key = (string) ( $settings['field_name'] ?? '' );
		if ( $key === '' ) {
			return;
		}
		add_filter(
			'posts_clauses',
			static function ( array $clauses, $q ) use ( $key, $order, $query ) {
				if ( $q !== $query ) {
					return $clauses;
				}
				global $wpdb;
				$alias = 'ck_je_sort';
				$clauses['join'] .= $wpdb->prepare(
					" LEFT JOIN {$wpdb->postmeta} AS {$alias} ON {$wpdb->posts}.ID = {$alias}.post_id AND {$alias}.meta_key = %s",
					$key
				);
				$clauses['orderby'] = "{$alias}.meta_value {$order}, {$wpdb->posts}.ID DESC";
				return $clauses;
			},
			10,
			2
		);
	}

	// ------------------------------------------------------------------
	// FilterableColumn — text contains
	// ------------------------------------------------------------------

	public function filter_value_keys(): array {
		return [ '' ];
	}

	public function render_filter( string $name_prefix, array $settings, array $current ): void {
		$field_name  = (string) ( $settings['field_name'] ?? '' );
		$placeholder = $field_name !== '' ? $field_name : __( 'JetEngine field', 'columnkit' );
		printf(
			'<input type="search" name="%s" value="%s" placeholder="%s" />',
			esc_attr( $name_prefix ),
			esc_attr( (string) ( $current[''] ?? '' ) ),
			esc_attr( $placeholder )
		);
	}

	public function apply_filter( WP_Query $query, array $settings, array $values ): void {
		$key = (string) ( $settings['field_name'] ?? '' );
		$v   = (string) ( $values[''] ?? '' );
		if ( $key === '' || $v === '' ) {
			return;
		}
		$raw_mq   = $query->get( 'meta_query' );
		$existing = is_array( $raw_mq ) ? $raw_mq : [];
		$existing[] = [ 'key' => $key, 'value' => $v, 'compare' => 'LIKE' ];
		$query->set( 'meta_query', $existing );
	}
}
