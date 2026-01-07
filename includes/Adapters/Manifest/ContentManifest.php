<?php
/**
 * Content manifest class.
 *
 * @package AI_Importer
 */

namespace AI_Importer\Adapters\Manifest;

use ArrayIterator;
use Countable;
use DateTimeImmutable;
use IteratorAggregate;
use Traversable;

/**
 * Represents a collection of content items from a source adapter.
 *
 * @implements IteratorAggregate<string, ManifestItem>
 */
class ContentManifest implements Countable, IteratorAggregate {

	/**
	 * Collection of manifest items keyed by ID.
	 *
	 * @var array<string, ManifestItem>
	 */
	private array $items = array();

	/**
	 * Source adapter ID.
	 *
	 * @var string
	 */
	private string $source_id;

	/**
	 * Timestamp when the manifest was generated.
	 *
	 * @var DateTimeImmutable
	 */
	private DateTimeImmutable $generated_at;

	/**
	 * Constructor.
	 *
	 * @param string $source_id Source adapter identifier.
	 */
	public function __construct( string $source_id ) {
		$this->source_id    = $source_id;
		$this->generated_at = new DateTimeImmutable();
	}

	/**
	 * Get the source adapter ID.
	 *
	 * @return string Source ID.
	 */
	public function get_source_id(): string {
		return $this->source_id;
	}

	/**
	 * Get the generation timestamp.
	 *
	 * @return DateTimeImmutable Generation time.
	 */
	public function get_generated_at(): DateTimeImmutable {
		return $this->generated_at;
	}

	/**
	 * Add an item to the manifest.
	 *
	 * @param ManifestItem $item The item to add.
	 * @return void
	 */
	public function add_item( ManifestItem $item ): void {
		$this->items[ $item->id ] = $item;
	}

	/**
	 * Remove an item from the manifest.
	 *
	 * @param string $id Item ID to remove.
	 * @return bool True if item was removed, false if not found.
	 */
	public function remove_item( string $id ): bool {
		if ( isset( $this->items[ $id ] ) ) {
			unset( $this->items[ $id ] );
			return true;
		}
		return false;
	}

	/**
	 * Get an item by ID.
	 *
	 * @param string $id Item ID.
	 * @return ManifestItem|null The item or null if not found.
	 */
	public function get_item( string $id ): ?ManifestItem {
		return $this->items[ $id ] ?? null;
	}

	/**
	 * Check if an item exists.
	 *
	 * @param string $id Item ID.
	 * @return bool True if item exists.
	 */
	public function has_item( string $id ): bool {
		return isset( $this->items[ $id ] );
	}

	/**
	 * Get all items.
	 *
	 * @return array<string, ManifestItem> All items.
	 */
	public function get_items(): array {
		return $this->items;
	}

	/**
	 * Get items filtered by content type.
	 *
	 * @param ContentType $type The content type to filter by.
	 * @return array<string, ManifestItem> Filtered items.
	 */
	public function get_items_by_type( ContentType $type ): array {
		return array_filter(
			$this->items,
			fn( ManifestItem $item ) => $item->type === $type
		);
	}

	/**
	 * Get items that have media attachments.
	 *
	 * @return array<string, ManifestItem> Items with media.
	 */
	public function get_items_with_media(): array {
		return array_filter(
			$this->items,
			fn( ManifestItem $item ) => $item->has_media()
		);
	}

	/**
	 * Get the date range of items in the manifest.
	 *
	 * @return array{earliest: DateTimeImmutable|null, latest: DateTimeImmutable|null} Date range.
	 */
	public function get_date_range(): array {
		if ( empty( $this->items ) ) {
			return array(
				'earliest' => null,
				'latest'   => null,
			);
		}

		$dates = array_map(
			fn( ManifestItem $item ) => $item->created_at,
			$this->items
		);

		usort( $dates, fn( DateTimeImmutable $a, DateTimeImmutable $b ) => $a <=> $b );

		return array(
			'earliest' => $dates[0],
			'latest'   => end( $dates ),
		);
	}

	/**
	 * Get statistics about the manifest contents.
	 *
	 * @return array<string, int|array<string, int>> Statistics.
	 */
	public function get_stats(): array {
		$stats = array(
			'total'      => count( $this->items ),
			'with_media' => count( $this->get_items_with_media() ),
			'by_type'    => array(),
		);

		foreach ( ContentType::cases() as $type ) {
			$count = count( $this->get_items_by_type( $type ) );
			if ( $count > 0 ) {
				$stats['by_type'][ $type->value ] = $count;
			}
		}

		return $stats;
	}

	/**
	 * Get the number of items in the manifest.
	 *
	 * @return int Item count.
	 */
	public function count(): int {
		return count( $this->items );
	}

	/**
	 * Check if the manifest is empty.
	 *
	 * @return bool True if empty.
	 */
	public function is_empty(): bool {
		return empty( $this->items );
	}

	/**
	 * Get iterator for items.
	 *
	 * @return Traversable<string, ManifestItem> Iterator.
	 */
	public function getIterator(): Traversable {
		return new ArrayIterator( $this->items );
	}

	/**
	 * Convert the manifest to an array.
	 *
	 * @return array<string, mixed> Array representation.
	 */
	public function to_array(): array {
		return array(
			'source_id'    => $this->source_id,
			'generated_at' => $this->generated_at->format( 'c' ),
			'stats'        => $this->get_stats(),
			'items'        => array_map(
				fn( ManifestItem $item ) => $item->to_array(),
				$this->items
			),
		);
	}

	/**
	 * Create a ContentManifest from an array.
	 *
	 * @param array<string, mixed> $data Manifest data.
	 * @return self New ContentManifest instance.
	 * @throws \InvalidArgumentException If required data is missing.
	 */
	public static function from_array( array $data ): self {
		if ( ! isset( $data['source_id'] ) ) {
			throw new \InvalidArgumentException( 'Missing required field: source_id' );
		}

		$manifest = new self( $data['source_id'] );

		if ( isset( $data['generated_at'] ) ) {
			$manifest->generated_at = new DateTimeImmutable( $data['generated_at'] );
		}

		if ( isset( $data['items'] ) && is_array( $data['items'] ) ) {
			foreach ( $data['items'] as $item_data ) {
				$manifest->add_item( ManifestItem::from_array( $item_data ) );
			}
		}

		return $manifest;
	}
}
