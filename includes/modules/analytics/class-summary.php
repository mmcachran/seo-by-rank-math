<?php
/**
 * The Analytics Module
 *
 * @since      1.0.49
 * @package    RankMath
 * @subpackage RankMath\modules
 * @author     Rank Math <support@rankmath.com>
 */

namespace RankMath\Analytics;

use Exception;
use RankMath\Helper;
use RankMath\Google\Api;
use RankMath\Traits\Hooker;
use MyThemeShop\Helpers\Param;

defined( 'ABSPATH' ) || exit;

/**
 * Summary class.
 */
class Summary {

	/**
	 * Get Optimization stats.
	 *
	 * @return object
	 */
	public function get_optimization_summary() {
		global $wpdb;

		$stats = new \stdClass();

		$stats->good = DB::objects()->selectCount( 'object_id', 'count' )
			->where( 'is_indexable', 1 )
			->whereBetween( 'seo_score', [ 81, 100 ] )
			->getVar();

		$stats->ok = DB::objects()->selectCount( 'object_id', 'count' )
			->where( 'is_indexable', 1 )
			->whereBetween( 'seo_score', [ 51, 80 ] )
			->getVar();

		$stats->bad = DB::objects()->selectCount( 'object_id', 'count' )
			->where( 'is_indexable', 1 )
			->whereBetween( 'seo_score', [ 1, 50 ] )
			->getVar();

		$stats->noData = DB::objects()->selectCount( 'object_id', 'count' ) // phpcs:ignore
			->where( 'is_indexable', 1 )
			->where( 'seo_score', 0 )
			->getVar();

		$stats->total   = $stats->good + $stats->ok + $stats->bad + $stats->noData; // phpcs:ignore
		$stats->average = 0;

		// Average.
		$average = DB::objects()
			->selectCount( 'object_id', 'total' )
			->selectSum( 'seo_score', 'score' )
			->one();

		$average->total += $stats->noData; // phpcs:ignore

		if ( $average->total > 0 ) {
			$stats->average = $average->score / $average->total;
			$stats->average = \round( $stats->average, 2 );
		}

		return $stats;
	}

	/**
	 * Get console data/
	 *
	 * @return object
	 */
	public function get_analytics_summary() {
		$stats = DB::analytics()
			->selectCount( 'DISTINCT(page)', 'posts' )
			->selectSum( 'impressions', 'impressions' )
			->selectSum( 'clicks', 'clicks' )
			->selectAvg( 'position', 'position' )
			->selectAvg( 'ctr', 'ctr' )
			->whereBetween( 'created', [ $this->start_date, $this->end_date ] )
			->where( 'clicks', '>', 0 )
			->one();

		$old_stats = DB::analytics()
			->selectCount( 'DISTINCT(page)', 'posts' )
			->selectSum( 'impressions', 'impressions' )
			->selectSum( 'clicks', 'clicks' )
			->selectAvg( 'position', 'position' )
			->selectAvg( 'ctr', 'ctr' )
			->whereBetween( 'created', [ $this->compare_start_date, $this->compare_end_date ] )
			->where( 'clicks', '>', 0 )
			->one();

		$stats->clicks = [
			'total'      => (int) $stats->clicks,
			'previous'   => (int) $old_stats->clicks,
			'difference' => $stats->clicks - $old_stats->clicks,
		];

		$stats->impressions = [
			'total'      => (int) $stats->impressions,
			'previous'   => (int) $old_stats->impressions,
			'difference' => $stats->impressions - $old_stats->impressions,
		];

		$stats->position = [
			'total'      => (float) \number_format( $stats->position, 2 ),
			'previous'   => (float) \number_format( $old_stats->position, 2 ),
			'difference' => (float) \number_format( $stats->position - $old_stats->position, 2 ),
		];

		$stats->ctr = [
			'total'      => (float) \number_format( $stats->ctr, 2 ),
			'previous'   => (float) \number_format( $old_stats->ctr, 2 ),
			'difference' => (float) \number_format( $stats->ctr - $old_stats->ctr, 2 ),
		];

		$stats->keywords  = $this->get_keywords_summary();
		$stats->graph     = $this->get_analytics_summary_graph();

		return array_filter( (array) $stats );
	}

	/**
	 * Get posts summary.
	 *
	 * @return object
	 */
	public function get_posts_summary() {
		$cache_key = $this->get_cache_key( 'posts_summary', $this->days . 'days' );
		$cache     = get_transient( $cache_key );

		if ( false !== $cache ) {
			return $cache;
		}

		$summary = DB::analytics()
			->selectCount( 'DISTINCT(page)', 'posts' )
			->selectSum( 'impressions', 'impressions' )
			->selectSum( 'clicks', 'clicks' )
			->whereBetween( 'created', [ $this->start_date, $this->end_date ] )
			->where( 'clicks', '>', 0 )
			->one();

		set_transient( $cache_key, $summary, DAY_IN_SECONDS );

		return $summary;
	}

	/**
	 * Get keywords summary.
	 *
	 * @return array
	 */
	public function get_keywords_summary() {
		global $wpdb;

		$query = $wpdb->prepare(
			"SELECT ROUND(AVG(keywords),0) as keywords, SUM(impressions) AS impressions, SUM(clicks) AS clicks, AVG(ctr) AS ctr
			 FROM (
			    SELECT count(DISTINCT(query)) AS keywords, SUM(impressions) AS impressions, SUM(clicks) AS clicks, AVG(ctr) AS ctr
				FROM {$wpdb->prefix}rank_math_analytics_gsc
				WHERE clicks > 0 AND created BETWEEN %s AND %s
			    GROUP BY created
			) as ks",
			$this->start_date,
			$this->end_date
		);
		$stats = $wpdb->get_row( $query ); // phpcs:ignore

		$query     = $wpdb->prepare(
			"SELECT ROUND(AVG(keywords),0) as keywords, SUM(impressions) AS impressions, SUM(clicks) AS clicks, AVG(ctr) AS ctr
			 FROM (
				 SELECT count(DISTINCT(query)) AS keywords, SUM(impressions) AS impressions, SUM(clicks) AS clicks, AVG(ctr) AS ctr
 				FROM {$wpdb->prefix}rank_math_analytics_gsc
 				WHERE clicks > 0 AND created BETWEEN %s AND %s
 			    GROUP BY created
			) as ks",
			$this->compare_start_date,
			$this->compare_end_date
		);
		$old_stats = $wpdb->get_row( $query ); // phpcs:ignore

		$keywords           = new \stdClass();
		$keywords->keywords = [
			'total'      => (int) $stats->keywords,
			'previous'   => (int) $old_stats->keywords,
			'difference' => $stats->keywords - $old_stats->keywords,
		];

		$keywords->clicks = [
			'total'      => (int) $stats->clicks,
			'previous'   => (int) $old_stats->clicks,
			'difference' => $stats->clicks - $old_stats->clicks,
		];

		$keywords->impressions = [
			'total'      => (int) $stats->impressions,
			'previous'   => (int) $old_stats->impressions,
			'difference' => $stats->impressions - $old_stats->impressions,
		];

		$keywords->ctr = [
			'total'      => (float) \number_format( $stats->ctr, 2 ),
			'previous'   => (float) \number_format( $old_stats->ctr, 2 ),
			'difference' => (float) \number_format( $stats->ctr - $old_stats->ctr, 2 ),
		];

		$keywords->graph = $this->get_analytics_summary_graph();

		return $keywords;
	}

	/**
	 * Get graph data.
	 *
	 * @return array
	 */
	public function get_analytics_summary_graph() {
		global $wpdb;

		$data     = new \stdClass();
		$interval = $this->get_sql_range( 'created' );

		$data->analytics = DB::analytics()
			->distinct()
			->select( 'DATE_FORMAT( created,\'%Y-%m-%d\') as date' )
			->selectSum( 'impressions', 'impressions' )
			->selectSum( 'clicks', 'clicks' )
			->selectAvg( 'position', 'position' )
			->selectAvg( 'ctr', 'ctr' )
			->whereBetween( 'created', [ $this->start_date, $this->end_date ] )
			->where( 'clicks', '>', 0 )
			->groupBy( $interval )
			->orderBy( 'created', 'ASC' )
			->get();

		// phpcs:disable
		$query = $wpdb->prepare(
			"SELECT DATE_FORMAT( created, '%%Y-%%m-%%d') as date, ROUND(AVG(keywords),0) as keywords
			 FROM (
			    SELECT created, count(DISTINCT(query)) AS keywords
				FROM {$wpdb->prefix}rank_math_analytics_gsc
				WHERE clicks > 0 AND created BETWEEN %s AND %s
			    GROUP BY created
			) as ka
			GROUP BY {$interval}",
			$this->start_date,
			$this->end_date
		);
		$data->keywords = $wpdb->get_results( $query );
		// phpcs:enable

		$intervals    = $this->get_intervals();
		$data->merged = $this->get_date_array(
			$intervals['dates'],
			[
				'clicks'      => [],
				'impressions' => [],
				'position'    => [],
				'ctr'         => [],
				'keywords'    => [],
				'pageviews'   => [],
			]
		);

		// Convert types.
		$data->analytics = array_map( [ $this, 'normalize_graph_rows' ], $data->analytics );

		// Merge for performance.
		$data->merged = $this->get_merge_data_graph( $data->analytics, $data->merged, $intervals['map'] );
		$data->merged = $this->get_merge_data_graph( $data->keywords, $data->merged, $intervals['map'] );
		$data->merged = $this->get_graph_data_flat( $data->merged );
		$data->merged = array_values( $data->merged );

		return $data;
	}

	/**
	 * Sort by time.
	 *
	 * @param  string $date1 Date to compare.
	 * @param  string $date2 Date to compare.
	 * @return int
	 */
	protected function sort_by_time( $date1, $date2 ) {
		$time1 = strtotime( $date1 );
		$time2 = strtotime( $date2 );

		if ( $time1 > $time2 ) {
			return 1;
		}

		if ( $time1 < $time2 ) {
			return -1;
		}

		return 0;
	}
}
