<?php
declare( strict_types=1 );

namespace ColumnKit;

use ColumnKit\Columns\ColumnInterface;

final class ColumnRegistry {
	/** @var array<string, ColumnInterface> keyed by type slug */
	private array $types = [];

	public function register( ColumnInterface $column ): void {
		$this->types[ $column->get_type() ] = $column;
	}

	public function has( string $type ): bool {
		return isset( $this->types[ $type ] );
	}

	public function get( string $type ): ?ColumnInterface {
		return $this->types[ $type ] ?? null;
	}

	/** @return array<string, ColumnInterface> */
	public function all(): array {
		return $this->types;
	}

	/** @return string[] */
	public function type_slugs(): array {
		return array_keys( $this->types );
	}
}
