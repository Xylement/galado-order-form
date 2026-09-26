<?php
/**
 * Every date rule is in Malaysian time (Asia/Kuala_Lumpur, UTC+8, no daylight saving), whatever
 * the server clock or the WordPress timezone setting say. Pure functions: unit-tested directly.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Galado_GC_Time {

    public static function tz() {
        return new DateTimeZone(Galado_GC_Config::TIMEZONE);
    }

    /** Today's date in Malaysia, Y-m-d. */
    public static function today($now = null) {
        return self::at($now === null ? time() : (int) $now)->format('Y-m-d');
    }

    /**
     * The moment a card expires: 23:59:59 Malaysian time on the same calendar date three years after
     * purchase. WooCommerce rejects a coupon once time() passes this, so the card works all of its
     * last day. A card bought on 29 February expires on 28 February (the anniversary does not exist).
     */
    public static function expiry_timestamp($purchase_ts) {
        $bought = self::at((int) $purchase_ts);
        $year = (int) $bought->format('Y') + Galado_GC_Config::VALID_YEARS;
        $month = (int) $bought->format('n');
        $day = (int) $bought->format('j');
        if (!checkdate($month, $day, $year)) {
            $day = (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), self::tz()))->format('t');
        }
        $end = new DateTimeImmutable(sprintf('%04d-%02d-%02d 23:59:59', $year, $month, $day), self::tz());
        return $end->getTimestamp();
    }

    /**
     * When the recipient's email goes out: 09:00 Malaysian time on the chosen date, or right now if
     * that moment has passed (the date is today after 9am, or in the past).
     */
    public static function delivery_timestamp($date_ymd, $now = null) {
        $now = $now === null ? time() : (int) $now;
        $at = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $date_ymd, self::tz());
        if (!$at) {
            return $now;
        }
        $ts = $at->setTime(Galado_GC_Config::DELIVERY_HOUR, 0, 0)->getTimestamp();
        return max($ts, $now);
    }

    /** A delivery date the buyer may pick: today up to one year ahead, Malaysian dates. */
    public static function is_valid_delivery_date($date_ymd, $now = null) {
        $date_ymd = (string) $date_ymd;
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date_ymd, self::tz());
        if (!$d || $d->format('Y-m-d') !== $date_ymd) {
            return false;
        }
        $today = self::today($now);
        $last = self::at($now === null ? time() : (int) $now)
            ->modify('+' . Galado_GC_Config::DELIVERY_MAX_DAYS_AHEAD . ' days')->format('Y-m-d');
        return $date_ymd >= $today && $date_ymd <= $last;
    }

    /** Last date the picker allows, Y-m-d. */
    public static function last_delivery_date($now = null) {
        return self::at($now === null ? time() : (int) $now)
            ->modify('+' . Galado_GC_Config::DELIVERY_MAX_DAYS_AHEAD . ' days')->format('Y-m-d');
    }

    /** A date for customers: "26 September 2029", in Malaysian time. */
    public static function human_date($ts) {
        return self::at((int) $ts)->format('j F Y');
    }

    /** A Y-m-d date for customers: "26 September 2029". */
    public static function human_ymd($date_ymd) {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $date_ymd, self::tz());
        return $d ? $d->format('j F Y') : (string) $date_ymd;
    }

    private static function at($ts) {
        return (new DateTimeImmutable('@' . (int) $ts))->setTimezone(self::tz());
    }
}
