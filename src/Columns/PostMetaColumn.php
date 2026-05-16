<?php
declare( strict_types=1 );

namespace ColumnKit\Columns;

use WP_Query;

final class PostMetaColumn extends BaseColumn implements SortableColumn, FilterableColumn, EditableColumn {
	public function get_type(): string {
		return 'post_meta';
	}

	public function get_label(): string {
		return __( 'Custom Field', 'columnkit' );
	}

	public function get_description(): string {
		return __( 'Show the value of a post meta key.', 'columnkit' );
	}

	public function applies_to_screen( string $screen_key ): bool {
		return str_starts_with( $screen_key, 'post_type:' ) || $screen_key === 'media';
	}

	public function settings_fields(): array {
		return [
			[
				'key'      => 'meta_key',
				'label'    => __( 'Meta key', 'columnkit' ),
				'type'     => 'text',
				'required' => true,
			],
			[
				'key'     => 'value_type',
				'label'   => __( 'Value type', 'columnkit' ),
				'type'    => 'select',
				'options' => [
					'string'  => __( 'Text', 'columnkit' ),
					'numeric' => __( 'Number', 'columnkit' ),
					'date'    => __( 'Date', 'columnkit' ),
					'boolean' => __( 'Yes / No', 'columnkit' ),
				],
			],
		];
	}

	public function sanitize_settings( array $input ): array {
		$out = parent::sanitize_settings( $input );
		// Normalise meta_key to a valid meta key (alphanumerics, underscore, hyphen, dot).
		if ( isset( $out['meta_key'] ) ) {
			$out['meta_key'] = preg_replace( '/[^A-Za-z0-9_\-.]/', '', $out['meta_key'] );
		}
		// Whitelist value_type.
		$allowed = [ 'string', 'numeric', 'date', 'boolean' ];
		if ( ! isset( $out['value_type'] ) || ! in_array( $out['value_type'], $allowed, true ) ) {
			$out['value_type'] = 'string';
		}
		return $out;
	}

	public function render( int $object_id, array $settings ): string {
		$key = (string) ( $settings['meta_key'] ?? '' );
		if ( $key === '' ) {
			return '';
		}
		$raw  = get_post_meta( $object_id, $key, true );
		$type = (string) ( $settings['value_type'] ?? 'string' );

		return $this->format( $raw, $type );
	}

	/** @param mixed $raw */
	private function format( $raw, string $type ): string {
		if ( is_array( $raw ) || is_object( $raw ) ) {
			$raw = wp_json_encode( $raw );
		}
		$raw = (string) $raw;

		switch ( $type ) {
			case 'numeric':
				if ( $raw === '' ) {
					return '';
				}
				return esc_html( is_numeric( $raw ) ? number_format_i18n( (float) $raw, $this->decimals( $raw ) ) : $raw );

			case 'date':
				$ts = $this->parse_date( $raw );
				if ( $ts === null ) {
					return esc_html( $raw );
				}
				return esc_html( wp_date( (string) get_option( 'date_format', 'Y-m-d' ), $ts ) );

			case 'boolean':
				$truthy = in_array( strtolower( $raw ), [ '1', 'true', 'yes', 'on' ], true );
				return esc_html( $truthy ? __( 'Yes', 'columnkit' ) : __( 'No', 'columnkit' ) );

			case 'string':
			default:
				return esc_html( $raw );
		}
	}

	private function decimals( string $value ): int {
		$pos = strpos( $value, '.' );
		return $pos === false ? 0 : min( strlen( $value ) - $pos - 1, 6 );
	}

	private function parse_date( string $raw ): ?int {
		if ( $raw === '' ) {
			return null;
		}
		if ( ctype_digit( $raw ) ) {
			return (int) $raw;
		}
		$ts = strtotime( $raw );
		return $ts === false ? null : $ts;
	}

	// ------------------------------------------------------------------
	// SortableColumn
	// ------------------------------------------------------------------

	/**
	 * Sort by meta value using a LEFT JOIN — posts missing the meta still appear (and sort
	 * either last or with NULL leading depending on direction; that's MySQL default behaviour
	 * and we accept it for the common case).
	 *
	 * Why posts_clauses instead of WP's meta_key/orderby=meta_value: the latter does an INNER
	 * JOIN, silently hiding rows that don't have the meta. That surprises users.
	 */
	public function apply_sort( WP_Query $query, array $settings, string $order ): void {
		$key = (string) ( $settings['meta_key'] ?? '' );
		if ( $key === '' ) {
			return;
		}
		$type = (string) ( $settings['value_type'] ?? 'string' );

		add_filter(
			'posts_clauses',
			static function ( array $clauses, $q ) use ( $key, $type, $order, $query ) {
				if ( $q !== $query ) {
					return $clauses;
				}
				global $wpdb;
				$alias = 'ck_sort_meta';
				$clauses['join'] .= $wpdb->prepare(
					" LEFT JOIN {$wpdb->postmeta} AS {$alias} ON {$wpdb->posts}.ID = {$alias}.post_id AND {$alias}.meta_key = %s",
					$key
				);
				$expr = "{$alias}.meta_value";
				if ( $type === 'numeric' ) {
					$expr = "CAST({$expr} AS DECIMAL(20,6))";
				} elseif ( $type === 'date' ) {
					$expr = "CAST({$expr} AS DATETIME)";
				}
				// $order is already whitelisted to ASC|DESC by SortManager.
				$clauses['orderby'] = "{$expr} {$order}, {$wpdb->posts}.ID DESC";
				return $clauses;
			},
			10,
			2
		);
	}

	// ------------------------------------------------------------------
	// FilterableColumn
	// ------------------------------------------------------------------

	public function filter_value_keys(): array {
		// Always advertise all suffixes; render_filter decides which to show per value_type.
		return [ '', 'min', 'max' ];
	}

	public function render_filter( string $name_prefix, array $settings, array $current ): void {
		$type     = (string) ( $settings['value_type'] ?? 'string' );
		$meta_key = (string) ( $settings['meta_key'] ?? '' );
		$label    = $meta_key !== '' ? $meta_key : __( 'meta', 'columnkit' );

		if ( $type === 'numeric' || $type === 'date' ) {
			$input_type = $type === 'date' ? 'date' : 'number';
			printf(
				'<input type="%s" name="%s__min" value="%s" placeholder="%s" style="width:9em" /> ',
				esc_attr( $input_type ),
				esc_attr( $name_prefix ),
				esc_attr( (string) ( $current['min'] ?? '' ) ),
				/* translators: %s: meta key name */
				esc_attr( sprintf( __( '%s ≥', 'columnkit' ), $label ) )
			);
			printf(
				'<input type="%s" name="%s__max" value="%s" placeholder="%s" style="width:9em" />',
				esc_attr( $input_type ),
				esc_attr( $name_prefix ),
				esc_attr( (string) ( $current['max'] ?? '' ) ),
				/* translators: %s: meta key name */
				esc_attr( sprintf( __( '%s ≤', 'columnkit' ), $label ) )
			);
			return;
		}

		if ( $type === 'boolean' ) {
			$v = (string) ( $current[''] ?? '' );
			echo '<select name="' . esc_attr( $name_prefix ) . '">';
			printf( '<option value="">%s</option>', esc_html( sprintf( /* translators: %s: meta key */ __( '%s: any', 'columnkit' ), $label ) ) );
			printf( '<option value="1" %s>%s</option>', selected( $v, '1', false ), esc_html__( 'Yes', 'columnkit' ) );
			printf( '<option value="0" %s>%s</option>', selected( $v, '0', false ), esc_html__( 'No', 'columnkit' ) );
			echo '</select>';
			return;
		}

		// Default: text contains.
		printf(
			'<input type="search" name="%s" value="%s" placeholder="%s" />',
			esc_attr( $name_prefix ),
			esc_attr( (string) ( $current[''] ?? '' ) ),
			esc_attr( $label )
		);
	}

	// ------------------------------------------------------------------
	// EditableColumn
	// ------------------------------------------------------------------

	public function get_raw_value( int $object_id, array $settings ): string {
		$key = (string) ( $settings['meta_key'] ?? '' );
		if ( $key === '' ) {
			return '';
		}
		$raw = get_post_meta( $object_id, $key, true );
		if ( is_array( $raw ) || is_object( $raw ) ) {
			$raw = wp_json_encode( $raw );
		}
		return (string) $raw;
	}

	public function get_edit_input_type( array $settings ): string {
		$type = (string) ( $settings['value_type'] ?? 'string' );
		return match ( $type ) {
			'numeric' => 'number',
			'date'    => 'date',
			'boolean' => 'boolean',
			default   => 'text',
		};
	}

	public function get_edit_options( array $settings ): ?array {
		return null; // post_meta is never a fixed-options select.
	}

	public function render_bulk_edit_field( string $input_name, array $settings ): void {
		$type = (string) ( $settings['value_type'] ?? 'string' );

		if ( $type === 'boolean' ) {
			echo '<select name="' . esc_attr( $input_name ) . '" class="ck-edit-input">';
			printf( '<option value="">%s</option>', esc_html__( '— (unchanged)', 'columnkit' ) );
			printf( '<option value="1">%s</option>', esc_html__( 'Yes', 'columnkit' ) );
			printf( '<option value="0">%s</option>', esc_html__( 'No', 'columnkit' ) );
			echo '</select>';
			return;
		}

		$input_type = match ( $type ) {
			'numeric' => 'number',
			'date'    => 'date',
			default   => 'text',
		};
		$extra = $input_type === 'number' ? ' step="any"' : '';

		printf(
			'<input type="%1$s"%2$s name="%3$s" value="" class="ck-edit-input" />',
			esc_attr( $input_type ),
			$extra,
			esc_attr( $input_name )
		);
	}

	public function save_value( int $post_id, string $raw_value, array $settings ): void {
		$key = (string) ( $settings['meta_key'] ?? '' );
		if ( $key === '' ) {
			return;
		}
		// Protected meta (keys starting with "_", or any key WP/plugins flag protected) is
		// editable here only by users trusted to manage other people's content. Without this,
		// a user who merely has edit_post on a row (Author/Contributor on their own posts)
		// could tamper with internal meta — _wp_page_template, _thumbnail_id, another
		// plugin's access-control meta — just because an admin configured a column on that
		// key. Editors/Admins keep the feature; this mirrors the elevated-cap gate already
		// applied to the core "author" inline field in EditManager.
		if ( is_protected_meta( $key, 'post' ) ) {
			$post_type   = get_post_type( $post_id );
			$pt_obj      = $post_type ? get_post_type_object( $post_type ) : null;
			$trusted_cap = $pt_obj->cap->edit_others_posts ?? 'edit_others_posts';
			if ( ! current_user_can( $trusted_cap ) ) {
				return;
			}
		}
		$type = (string) ( $settings['value_type'] ?? 'string' );

		// Normalise per declared value_type.
		$value_to_store = $raw_value;
		switch ( $type ) {
			case 'numeric':
				if ( $raw_value === '' ) {
					delete_post_meta( $post_id, $key );
					return;
				}
				if ( ! is_numeric( $raw_value ) ) {
					return; // Reject non-numeric for numeric fields.
				}
				$value_to_store = $raw_value;
				break;

			case 'date':
				if ( $raw_value === '' ) {
					delete_post_meta( $post_id, $key );
					return;
				}
				// Accept YYYY-MM-DD or any strtotime-able string; persist as YYYY-MM-DD.
				$ts = strtotime( $raw_value );
				if ( $ts === false ) {
					return;
				}
				$value_to_store = gmdate( 'Y-m-d', $ts );
				break;

			case 'boolean':
				// '' = unchanged (caller should not have routed here), otherwise 1/0.
				if ( $raw_value === '' ) {
					return;
				}
				$value_to_store = in_array( strtolower( $raw_value ), [ '1', 'true', 'yes', 'on' ], true ) ? '1' : '0';
				break;

			case 'string':
			default:
				// Allow empty: that deletes the meta key.
				if ( $raw_value === '' ) {
					delete_post_meta( $post_id, $key );
					return;
				}
				$value_to_store = $raw_value;
				break;
		}

		update_post_meta( $post_id, $key, $value_to_store );
	}

	// ------------------------------------------------------------------
	// FilterableColumn (continued)
	// ------------------------------------------------------------------

	public function apply_filter( WP_Query $query, array $settings, array $values ): void {
		$key = (string) ( $settings['meta_key'] ?? '' );
		if ( $key === '' ) {
			return;
		}
		$type     = (string) ( $settings['value_type'] ?? 'string' );
		$raw_mq = $query->get( 'meta_query' );
		$existing = is_array( $raw_mq ) ? $raw_mq : [];

		if ( $type === 'numeric' || $type === 'date' ) {
			$min  = (string) ( $values['min'] ?? '' );
			$max  = (string) ( $values['max'] ?? '' );
			$kind = $type === 'numeric' ? 'NUMERIC' : 'DATE';

			if ( $min !== '' && $max !== '' ) {
				$existing[] = [
					'key'     => $key,
					'value'   => [ $min, $max ],
					'type'    => $kind,
					'compare' => 'BETWEEN',
				];
			} elseif ( $min !== '' ) {
				$existing[] = [
					'key'     => $key,
					'value'   => $min,
					'type'    => $kind,
					'compare' => '>=',
				];
			} elseif ( $max !== '' ) {
				$existing[] = [
					'key'     => $key,
					'value'   => $max,
					'type'    => $kind,
					'compare' => '<=',
				];
			}
		} elseif ( $type === 'boolean' ) {
			$v = (string) ( $values[''] ?? '' );
			if ( $v === '1' ) {
				$existing[] = [
					'key'     => $key,
					'value'   => [ '1', 'true', 'yes', 'on' ],
					'compare' => 'IN',
				];
			} elseif ( $v === '0' ) {
				$existing[] = [
					'relation' => 'OR',
					[ 'key' => $key, 'compare' => 'NOT EXISTS' ],
					[ 'key' => $key, 'value' => [ '1', 'true', 'yes', 'on' ], 'compare' => 'NOT IN' ],
				];
			}
		} else {
			$v = (string) ( $values[''] ?? '' );
			if ( $v !== '' ) {
				$existing[] = [
					'key'     => $key,
					'value'   => $v,
					'compare' => 'LIKE',
				];
			}
		}

		if ( $existing ) {
			$query->set( 'meta_query', $existing );
		}
	}
}
