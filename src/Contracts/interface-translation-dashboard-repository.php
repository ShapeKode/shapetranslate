<?php
/**
 * Translation dashboard repository contract.
 *
 * @package ShapeTranslate
 */

namespace ShapeTranslate\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Defines paginated reporting operations for post translation groups.
 */
interface TranslationDashboardRepositoryInterface {

	/**
	 * Queries source post IDs for the translation dashboard.
	 *
	 * @param array<string, mixed> $args Validated dashboard query arguments.
	 * @return array{items: array<int, int>, total: int}
	 */
	public function query_dashboard( array $args );
}
