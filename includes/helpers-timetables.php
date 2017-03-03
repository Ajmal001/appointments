<?php
/**
 * @author: WPMUDEV, Ignacio Cruz (igmoweb)
 * @version:
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * Check if an interval is a holiday for a worker
 *
 * @param int $worker_id
 * @param int $start
 * @param int $end
 * @param mixed $location
 *
 * @return bool
 */
function appointments_is_worker_holiday( $worker_id, $start, $end, $location = false ) {
	$is_holiday = false;

	$worker = appointments_get_worker( $worker_id );
	if ( ! $worker ) {
		$worker_exceptions = array();
		$exceptions = appointments_get_worker_exceptions( $worker, 'closed', $location );

		if ( is_object( $exceptions ) ) {
			$worker_exceptions = explode( ',', $exceptions->days );
		}
	}
	else {
		$worker_exceptions = $worker->get_exceptions( 'closed' );
	}

	if (
		in_array( date( 'Y-m-d', $start ), $worker_exceptions )
		|| in_array( date( 'Y-m-d', $end ), $worker_exceptions )
	) {
		return true;
	}

	return apply_filters( 'app_is_holiday', $is_holiday, $start, $end, null, $worker_id );
}


/**
 * Check if an interval is a break
 *
 * @param int $start
 * @param int $end
 * @param int $worker_id Worker ID or 0 to check agains the main working days
 *
 * @return bool
 */
function appointments_is_interval_break( $start, $end, $worker_id = 0, $location = 0 ) {
	// Try getting cached preprocessed hours
	$days = array();

	// Preprocess and cache workhours
	// Look where our working hour ends
	$result_days = appointments_get_worker_working_hours( 'closed', $worker_id, $location );
	if ( $result_days && is_object( $result_days ) && ! empty( $result_days->hours ) ) {
		$days = $result_days->hours;
	}

	if ( ! is_array( $days ) || empty( $days ) ) {
		return false;
	}

	if ( is_array( $days ) ) {
		$weekday_number = date( "N", $start );

		foreach ( $days as $weekday => $day ) {
			if ( ! is_array( $day['start'] ) ) {
				$start_break_datetime = strtotime( date( 'Y-m-d', $start ) . ' ' . $day['start'] . ':00' );
				if ( $day['end'] === '00:00' ) {
					// This means that the end time is on the next day
					$end_break_datetime = strtotime( date( 'Y-m-d 00:00:00', strtotime( '+1 day', $start ) ) );
				}
				else {
					$end_break_datetime   = strtotime( date( 'Y-m-d', $start ) . ' ' . $day['end'] . ':00' );
				}


				if ( absint( $weekday_number ) === absint( $day['weekday_number'] ) && 'yes' === $day['active'] ) {
					// The weekday we're looking for and the break time is active
					$period = new App_Period( $start, $end );
					if ( $period->contains( $start_break_datetime, $end_break_datetime ) ) { // The end time is less than the break day end time. At this point we know that the searched dates are inside the interval) ) {
						return true;
					}
				} elseif ( absint( $weekday_number ) === absint( $day['weekday_number'] ) && is_array( $day['active'] ) ) {
					// The weekday we're looking for and the break day is composed by several times
					foreach ( $day["active"] as $idx => $is_active ) {
						if (
							$start >= $start_break_datetime // The start time is greater than the break day start time
							&& $end <= $end_break_datetime // The end time is less than the break day end time. At this point we know that the searched dates are inside the interval)
						) {
							return true;
						}
					}
				}
			}
			else {
				foreach ( $day['start'] as $key => $day_start ) {
					$day_end = $day['end'][ $key ];
					$is_active = $day['active'][ $key ];
					$start_break_datetime = strtotime( date( 'Y-m-d', $start ) . ' ' . $day_start . ':00' );
					if ( $day_end === '00:00' ) {
						// This means that the end time is on the next day
						$end_break_datetime = strtotime( date( 'Y-m-d 00:00:00', strtotime( '+1 day', $start ) ) );
					}
					else {
						$end_break_datetime   = strtotime( date( 'Y-m-d', $start ) . ' ' . $day_end . ':00' );
					}


					if ( absint( $weekday_number ) === absint( $day['weekday_number'] ) && 'yes' === $is_active ) {
						$period = new App_Period( $start, $end );
						// The weekday we're looking for and the break time is active
						if ( $period->contains( $start_break_datetime, $end_break_datetime ) ) {
							return true;
						}
					}
				}
			}

		}
	}

	return false;
}


/**
 * Return the number of workers available for a given service during a interval of time
 *
 * Note: This function does not check agains worker's appointments
 *
 * @param int $start
 * @param int $end
 * @param int $service_id
 * @param bool|int $location
 *
 * @return int
 */
function appointments_get_available_workers_for_interval( $start, $end, $service_id, $location = false ) {
	$capacity = apply_filters( 'app_get_capacity', false, $service_id, null );
	if ( false !== $capacity ) {
		// Dont proceed further if capacity is forced
		return $capacity;
	}

	$service = appointments_get_service( $service_id );
	if ( ! $service ) {
		// This will mostly happen when Appointments->service = 0
		return 1;
	}

	$workers = appointments_get_workers_by_service( $service_id );
	if ( ! $workers ) {
		// If there are no workers for this service, apply the service capacity
		return appointments_get_capacity();
	}

	$capacity_available = 0;
	$available_workers = array();

	foreach( $workers as $worker ) {
		if ( appointments_is_worker_holiday( $worker->ID, $start, $end, $location ) ) {
			// If it's a worker exception, do not account this worker
			continue;
		}

		if ( appointments_is_interval_break( $start, $end, $worker->ID, $location ) ) {
			// Break for the worker, do not account
			continue;
		}

		// Try getting cached preprocessed hours
		$days = array();
		$result_days = appointments_get_worker_working_hours( 'open', $worker->ID, $location );
		if ( $result_days && is_object( $result_days ) && ! empty( $result_days->hours ) ) {
			$days = $result_days->hours;
		}

		if ( ! is_array( $days ) || empty( $days ) ) {
			continue;
		}


		if ( is_array( $days ) ) {
			// Filter days. Just get the correspondant weekday and if it's active
			$active_day = wp_list_filter(
				$days,
				array(
					'weekday_number' => date( "N", $start ),
					'active'         => 'yes'
				)
			);
			if ( ! empty( $active_day ) ) {
				// Results must only contain one result, let's get that result
				$active_day = current( $active_day );

				// The worker is working this weekday. Let's check the interval
				$start_active_datetime = strtotime( date( 'Y-m-d', $start ) . ' ' . $active_day['start'] . ':00' );
				if ( $active_day['end'] === '00:00' ) {
					// This means that the end time is on the next day
					$end_active_datetime = strtotime( date( 'Y-m-d 00:00:00', strtotime( '+1 day', $start ) ) );
				}
				else {
					$end_active_datetime   = strtotime( date( 'Y-m-d', $start ) . ' ' . $active_day['end'] . ':00' );
				}

				if (
					$start >= $start_active_datetime // The start time is greater than the active day start time
					&& $end <= $end_active_datetime // The end time is less than the active day end time. At this point we know that the searched dates are inside the interval
				) {
					$capacity_available++;
				}
			}
			unset( $active_day );
		}
	}

	// We have to check service capacity too
	if ( ! $service->capacity ) {
		// No service capacity limit
		return $capacity_available;
	}

	// Return whichever smaller
	return min( $service->capacity, $capacity_available );
}


/**
 * Get the min and max working hours for a week given a worker and location
 *
 * @param int $worker_id
 * @param int $location_id
 *
 * @return array|bool|mixed
 */
function appointments_get_min_max_working_hours( $worker_id = 0, $location_id = 0 ) {

	$cached = wp_cache_get( 'app_max_min_working_hours' );
	if ( ! is_array( $cached ) ) {
		$cached = array();
	}

	$cache_key = $worker_id . '-' . $location_id;
	if ( isset( $cached[ $cache_key ] ) ) {
		return $cached[ $cache_key ];
	}

	$result = appointments_get_worker_working_hours( 'open', $worker_id, $location_id );
	if ( $result ) {
		$days = $result->hours;
		$days = array_filter( $days );
		if ( is_array( $days ) ) {
			$min = 24;
			$max = 0;
			foreach ( $days as $day ) {
				if ( ! isset( $day['start'] ) || ! isset( $day['end'] ) ) {
					continue;
				}

				$start         = date( "G", strtotime( $day['start'] ) );
				$end_timestamp = strtotime( $day['end'] );
				$end           = date( "G", $end_timestamp );
				// Add 1 hour if there are some minutes left. e.g. for 10:10pm, make max as 23
				if ( '00' != date( "i", $end_timestamp ) && $end != 24 ) {
					$end = $end + 1;
				}
				if ( $start < $min ) {
					$min = $start;
				}
				if ( $end > $max ) {
					$max = $end;
				}
				// Special case: If end is 0:00, regard it as 24
				if ( 0 == $end && '00' == date( "i", $end_timestamp ) ) {
					$max = 24;
				}
			}

			$min_max = array( "min" => absint( $min ), "max" => absint( $max ) );
			$cached[ $cache_key ] = $min_max;
			wp_cache_set( 'app_max_min_working_hours', $cached );
			return $min_max;
		}
	}
	return false;
}